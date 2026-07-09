<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * Read-only client for the Adaxes REST API — used to verify a person's *live*
 * Active Directory account against the golden record.
 *
 * The app already shows OneSync's *reported* per-destination state
 * (account_sync_status) and a NextGen↔PowerSchool field reconciliation. This
 * service adds the third leg: what AD itself currently holds, fetched on demand
 * when an admin opens a person — account enabled/disabled, sAMAccountName, UPN,
 * mail, displayName, OU/DN — so a drift between the source of truth and the
 * provisioned account is visible without leaving the page.
 *
 * Design mirrors VpnMonitorService: it is config-gated (does nothing unless the
 * ADAXES_* env is set), the HTTP call is injectable (so the logic is unit-tested
 * with no live server), and it NEVER throws — every path returns a result
 * envelope. It is strictly read-only: it gets/searches directory objects and
 * never writes, enables, or modifies anything.
 *
 * Authentication is HTTP Basic with a read-only service account
 * (ADAXES_USERNAME / ADAXES_PASSWORD). The base URL and the object/search paths
 * are configurable because they differ across Adaxes versions (current builds
 * serve under `…/restv2/`; older ones used `…/restApi/api/`).
 *
 * @phpstan-type Envelope array{ok:bool, error:?string, configured:bool, found:bool, by:?string, identifier:?string, attributes:array<string,string>, comparison:array<int,array{field:string,label:string,golden:string,ad:string,state:string}>, guid:?string}
 * @phpstan-type HttpResponse array{status:int, body:string}
 */
final class AdaxesService
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $token;
    private int $timeout;
    private string $objectsPath;
    private string $objectParam;
    private string $searchPath;
    private string $sessionPath;
    private string $tokenPath;
    private string $employeeIdAttr;

    /** Token + session resolved from the username/password handshake, per instance. */
    private ?string $resolvedToken = null;
    private ?string $resolvedSessionId = null;
    private bool $authAttempted = false;
    private ?string $authError = null;
    /** @var list<string> */
    private array $properties;
    /** @var callable(string,string,array<string,string>,?string):?array{status:int,body:string} */
    private $fetch;

    /** Default AD attributes pulled for the comparison panel. */
    private const DEFAULT_PROPERTIES = [
        'objectGUID', 'sAMAccountName', 'userPrincipalName', 'mail', 'displayName',
        'distinguishedName', 'accountDisabled', 'userAccountControl',
        'accountExpires', 'accountExpirationDate',
        'department', 'title', 'whenChanged',
    ];

    /**
     * @param callable(string,string,array<string,string>,?string):?array{status:int,body:string}|null $fetch
     *        ($method, $url, $headers, $body) → ['status'=>int,'body'=>string], or null on transport failure.
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null,
        ?int $timeout = null,
        ?callable $fetch = null,
        ?string $objectsPath = null,
        ?string $searchPath = null,
        ?array $properties = null,
        ?string $employeeIdAttr = null,
        ?string $token = null,
    ) {
        $this->baseUrl        = rtrim($baseUrl ?? (string) Config::get('ADAXES_BASE_URL', ''), '/');
        $this->username       = $username ?? (string) Config::get('ADAXES_USERNAME', '');
        $this->password       = $password ?? (string) Config::get('ADAXES_PASSWORD', '');
        $this->token          = trim($token ?? (string) Config::get('ADAXES_TOKEN', ''));
        $this->timeout        = $timeout ?? max(1, (int) Config::get('ADAXES_TIMEOUT', '5'));
        // All Adaxes REST endpoints live under {base}/api (matching the auth
        // handshake paths), so the object/search paths carry the api/ prefix too.
        $this->objectsPath    = trim($objectsPath ?? (string) Config::get('ADAXES_OBJECTS_PATH', 'api/directoryObjects'), '/');
        $this->objectParam    = trim((string) Config::get('ADAXES_OBJECT_PARAM', 'directoryObject')) ?: 'directoryObject';
        $this->searchPath     = trim($searchPath ?? (string) Config::get('ADAXES_SEARCH_PATH', 'api/directoryObjects/search'), '/');
        $this->sessionPath    = trim((string) Config::get('ADAXES_SESSION_PATH', 'api/authSessions/create'), '/');
        $this->tokenPath      = trim((string) Config::get('ADAXES_TOKEN_PATH', 'api/auth'), '/');
        $this->employeeIdAttr = trim($employeeIdAttr ?? (string) Config::get('ADAXES_EMPLOYEE_ID_ATTR', 'employeeID')) ?: 'employeeID';

        $configured = $properties ?? array_values(array_filter(array_map(
            'trim',
            explode(',', (string) Config::get('ADAXES_PROPERTIES', ''))
        )));
        $this->properties = $configured !== [] ? $configured : self::DEFAULT_PROPERTIES;

        $this->fetch = $fetch ?? fn(string $method, string $url, array $headers, ?string $body): ?array
            => $this->httpRequest($method, $url, $headers, $body);
    }

    /**
     * Live verification is available only when the base URL plus credentials are
     * set — either a security token (Adm-Authorization) or a Basic username +
     * password. The /restApi REST API generally requires the token.
     */
    public function configured(): bool
    {
        return $this->baseUrl !== '' && ($this->token !== '' || ($this->username !== '' && $this->password !== ''));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Verify a person's golden record against their live AD account.
     *
     * Lookup order: the AD objectGUID from the crosswalk (person_source_id where
     * system='ad') is tried first; if it doesn't resolve (the importers record an
     * alias/uniqueId there rather than a true objectGUID), or none is on file, we
     * search by any of sAMAccountName = username, mail = email, or
     * employeeID = employee_id (an LDAP OR — any one matches). With neither a
     * resolvable key nor any of those values there is nothing to compare against.
     *
     * @param array<string,mixed> $person     a person row (username, email, employee_id, upn, status, …)
     * @param array<int,array<string,mixed>> $sourceIds  person_source_id rows (system, source_key, is_active)
     * @return Envelope
     */
    public function verify(array $person, array $sourceIds): array
    {
        if (!$this->configured()) {
            return self::envelope(ok: false, configured: false, error: 'Adaxes is not configured (set ADAXES_BASE_URL and ADAXES_TOKEN, or ADAXES_USERNAME + ADAXES_PASSWORD).');
        }

        try {
            $guid = self::adObjectGuid($sourceIds);
            $res = null;
            $by = null;
            $identifier = null;

            // Try the crosswalk key first. It is *meant* to be an objectGUID/DN, but
            // the one-time importers record an alias/uniqueId there, so a clean
            // not-found (vs. an outage) falls through to the attribute search below.
            if ($guid !== null) {
                $res = $this->getObject($guid);
                if (!$res['ok']) {
                    return self::envelope(ok: false, configured: true, error: $res['error'], by: 'objectGUID', identifier: $guid);
                }
                if ($res['found']) {
                    $by = 'objectGUID';
                    $identifier = $guid;
                }
            }

            if ($by === null) {
                $criteria = $this->searchCriteria($person);
                if ($criteria === []) {
                    // Nothing left to try: a crosswalk key that didn't resolve, or no
                    // username/email/employee id to search on.
                    return self::envelope(ok: true, configured: true, found: false, by: $guid !== null ? 'objectGUID' : null, identifier: $guid);
                }
                $res = $this->searchByCriteria($criteria);
                $by = 'search';
                $identifier = self::criteriaSummary($criteria);
            }

            if (!$res['ok']) {
                return self::envelope(ok: false, configured: true, error: $res['error'], by: $by, identifier: $identifier);
            }
            if (!$res['found']) {
                return self::envelope(ok: true, configured: true, found: false, by: $by, identifier: $identifier);
            }

            return self::envelope(
                ok: true,
                configured: true,
                found: true,
                by: $by,
                identifier: $identifier,
                attributes: $res['attributes'],
                comparison: self::compareToGolden($person, $res['attributes']),
                guid: self::extractGuid($res['attributes']),
            );
        } finally {
            // Best-effort: if we minted a token via the handshake, terminate the
            // session + destroy the token so they don't linger until auto-expiry.
            // A static ADAXES_TOKEN created no session, so this is a no-op.
            $this->endSession();
        }
    }

    /**
     * GET a directory object by DN or objectGUID, requesting our property set.
     *
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function getObject(string $idOrDn): array
    {
        // The object is identified by a query parameter (directoryObject=<DN|GUID>),
        // not a path segment: GET {base}/api/directoryObjects?directoryObject=…&properties=…
        $url = $this->baseUrl . '/' . $this->objectsPath
             . '?' . $this->objectParam . '=' . rawurlencode($idOrDn)
             . '&properties=' . rawurlencode(implode(',', $this->properties));

        $res = $this->request('GET', $url);
        if (!$res['ok']) {
            // A 404 here means the object id is stale (deleted/renamed) OR the path
            // doesn't match this Adaxes version — either way fall through to the
            // attribute search rather than erroring.
            if ($res['status'] === 404) {
                return ['ok' => true, 'error' => null, 'found' => false, 'attributes' => []];
            }
            return ['ok' => false, 'error' => $res['error'], 'found' => false, 'attributes' => []];
        }

        return ['ok' => true, 'error' => null, 'found' => true, 'attributes' => self::normalizeProperties($res['data'])];
    }

    /**
     * Find a single user by any of username (sAMAccountName), email (mail), or
     * employee id (employeeID) — whichever the person record carries. Convenience
     * wrapper around searchByCriteria(); returns found=false when the person has
     * none of those values.
     *
     * @param array<string,mixed> $person
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function search(array $person): array
    {
        $criteria = $this->searchCriteria($person);
        if ($criteria === []) {
            return ['ok' => true, 'error' => null, 'found' => false, 'attributes' => []];
        }
        return $this->searchByCriteria($criteria);
    }

    /**
     * Search for a user matching ANY of the given attribute=value criteria and
     * return the first hit. The Adaxes search is a POST to {base}/api/
     * directoryObjects/search with a structured criteria document — a group of
     * "eq" conditions combined with OR (logicalOperator 2) so any one identifier
     * matches. Best-effort fallback when no objectGUID is on file.
     *
     * @param array<int,array{attr:string,value:string}> $criteria  non-empty
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function searchByCriteria(array $criteria): array
    {
        $conditions = [];
        foreach ($criteria as $c) {
            $conditions[] = [
                'type'                 => 0,            // condition node
                'property'             => $c['attr'],
                'operator'             => 'eq',
                'values'               => [['type' => 2, 'value' => $c['value']]],
                'valueLogicalOperator' => 0,
            ];
        }

        $body = [
            'criteria' => [
                'objectTypes' => [[
                    'type'  => 'User',
                    'items' => [
                        'type'            => 1,         // group node
                        'items'           => $conditions,
                        // 1 = AND, 2 = OR — OR so a match on any identifier counts.
                        'logicalOperator' => count($conditions) > 1 ? 2 : 1,
                    ],
                ]],
            ],
            'select' => ['properties' => implode(',', $this->properties)],
        ];

        $url = $this->baseUrl . '/' . $this->searchPath;
        $res = $this->request('POST', $url, (string) json_encode($body));
        if (!$res['ok']) {
            // A 404 on the search endpoint almost always means the base URL or
            // search path doesn't match this Adaxes version (vs. a real outage).
            $error = $res['status'] === 404
                ? $res['error'] . ' — check ADAXES_BASE_URL / ADAXES_SEARCH_PATH for your Adaxes version'
                : $res['error'];
            return ['ok' => false, 'error' => $error, 'found' => false, 'attributes' => []];
        }

        $first = self::firstSearchHit($res['data']);
        if ($first === null) {
            return ['ok' => true, 'error' => null, 'found' => false, 'attributes' => []];
        }
        return ['ok' => true, 'error' => null, 'found' => true, 'attributes' => self::normalizeProperties($first)];
    }

    /**
     * The attribute=value criteria to search on, drawn from whichever identifying
     * values the person record carries: username→sAMAccountName, email→mail,
     * employee_id→employeeID (attribute name configurable via ADAXES_EMPLOYEE_ID_ATTR).
     *
     * @param array<string,mixed> $person
     * @return array<int,array{attr:string,value:string}>
     */
    private function searchCriteria(array $person): array
    {
        $map = [
            'sAMAccountName'      => trim((string) ($person['username'] ?? '')),
            'mail'                => trim((string) ($person['email'] ?? '')),
            $this->employeeIdAttr => trim((string) ($person['employee_id'] ?? '')),
        ];
        $out = [];
        foreach ($map as $attr => $value) {
            if ($value !== '') {
                $out[] = ['attr' => $attr, 'value' => $value];
            }
        }
        return $out;
    }

    /**
     * Human-readable summary of the search criteria for the UI ("matched by …").
     *
     * @param array<int,array{attr:string,value:string}> $criteria
     */
    private static function criteriaSummary(array $criteria): string
    {
        return implode(', ', array_map(static fn($c) => $c['attr'] . '=' . $c['value'], $criteria));
    }

    /**
     * Field-by-field comparison of the golden record vs the live AD attributes.
     * State is one of match | differ | missing | info (same vocabulary the
     * NextGen↔PowerSchool reconciliation panel already uses).
     *
     * @param array<string,mixed>  $person
     * @param array<string,string> $attrs   normalized, lowercase-keyed AD attributes
     * @return array<int,array{field:string,label:string,golden:string,ad:string,state:string}>
     */
    public static function compareToGolden(array $person, array $attrs): array
    {
        $rows = [];

        $rows[] = self::compareRow('sAMAccountName', 'Username (sAMAccountName)', (string) ($person['username'] ?? ''), $attrs['samaccountname'] ?? null, caseInsensitive: true);
        $rows[] = self::compareRow('userPrincipalName', 'UPN', (string) ($person['upn'] ?? ''), $attrs['userprincipalname'] ?? null, caseInsensitive: true);
        $rows[] = self::compareRow('mail', 'Email', (string) ($person['email'] ?? ''), $attrs['mail'] ?? null, caseInsensitive: true);

        // Account enabled/disabled vs lifecycle status.
        $enabled = self::accountEnabled($attrs);
        $status = (string) ($person['status'] ?? '');
        $expectEnabled = in_array($status, ['active', 'pending'], true);
        if ($enabled === null) {
            $rows[] = ['field' => 'accountDisabled', 'label' => 'Account state', 'golden' => self::stateWord($expectEnabled), 'ad' => '', 'state' => 'missing'];
        } else {
            $rows[] = [
                'field'  => 'accountDisabled',
                'label'  => 'Account state',
                'golden' => self::stateWord($expectEnabled) . ' (status: ' . ($status ?: '—') . ')',
                'ad'     => self::stateWord($enabled),
                'state'  => $enabled === $expectEnabled ? 'match' : 'differ',
            ];
        }

        // Account expiration vs the golden end date.
        if (($expiryRow = self::expiryRow($person, $attrs)) !== null) {
            $rows[] = $expiryRow;
        }

        // Context-only attributes (no golden equivalent to match against).
        foreach (['displayname' => 'Display name', 'distinguishedname' => 'OU / DN', 'department' => 'Department', 'title' => 'Title'] as $key => $label) {
            if (($attrs[$key] ?? '') !== '') {
                $rows[] = ['field' => $key, 'label' => $label, 'golden' => '', 'ad' => $attrs[$key], 'state' => 'info'];
            }
        }

        return $rows;
    }

    /** Count of differing/missing comparison rows (the panel's headline number). */
    public static function diffCount(array $comparison): int
    {
        return count(array_filter($comparison, static fn($r) => in_array($r['state'], ['differ', 'missing'], true)));
    }

    /**
     * The identity values from a verify() envelope that may be adopted as the
     * golden record: sAMAccountName→username, userPrincipalName→upn, mail→email,
     * plus the objectGUID for the crosswalk link. Shaped exactly for
     * PersonWriter::linkAdAccount(). Returns empty strings (and null guid) when
     * the lookup found no account or the attribute is absent — the caller decides
     * whether there is anything worth writing.
     *
     * @param Envelope $envelope
     * @return array{guid:?string, username:string, upn:string, email:string}
     */
    public static function goldenCandidate(array $envelope): array
    {
        $attrs = is_array($envelope['attributes'] ?? null) ? $envelope['attributes'] : [];
        return [
            'guid'     => $envelope['guid'] ?? null,
            'username' => trim((string) ($attrs['samaccountname'] ?? '')),
            'upn'      => trim((string) ($attrs['userprincipalname'] ?? '')),
            'email'    => trim((string) ($attrs['mail'] ?? '')),
        ];
    }

    // ---- internals ----------------------------------------------------------

    /** @param array<int,array<string,mixed>> $sourceIds */
    private static function adObjectGuid(array $sourceIds): ?string
    {
        $fallback = null;
        foreach ($sourceIds as $row) {
            if (strtolower((string) ($row['system'] ?? '')) !== 'ad') {
                continue;
            }
            $key = trim((string) ($row['source_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!empty($row['is_active'])) {
                return $key; // prefer the active AD identity
            }
            $fallback ??= $key;
        }
        return $fallback;
    }

    /**
     * @return array{field:string,label:string,golden:string,ad:string,state:string}
     */
    private static function compareRow(string $field, string $label, string $golden, ?string $ad, bool $caseInsensitive = false): array
    {
        $ad ??= '';
        $golden = trim($golden);
        $ad = trim($ad);

        if ($golden === '' && $ad === '') {
            $state = 'info';
        } elseif ($golden === '' || $ad === '') {
            $state = 'missing';
        } else {
            $a = $caseInsensitive ? mb_strtolower($golden) : $golden;
            $b = $caseInsensitive ? mb_strtolower($ad) : $ad;
            $state = $a === $b ? 'match' : 'differ';
        }

        return ['field' => $field, 'label' => $label, 'golden' => $golden, 'ad' => $ad, 'state' => $state];
    }

    private static function stateWord(bool $enabled): string
    {
        return $enabled ? 'Enabled' : 'Disabled';
    }

    /**
     * Resolve whether the AD account is enabled from either an explicit
     * `accountDisabled` flag or the `userAccountControl` ADS_UF_ACCOUNTDISABLE
     * bit (0x2). Returns null when neither attribute was returned.
     *
     * @param array<string,string> $attrs
     */
    private static function accountEnabled(array $attrs): ?bool
    {
        if (array_key_exists('accountdisabled', $attrs) && $attrs['accountdisabled'] !== '') {
            return !self::truthy($attrs['accountdisabled']);
        }
        if (array_key_exists('useraccountcontrol', $attrs) && $attrs['useraccountcontrol'] !== '' && is_numeric($attrs['useraccountcontrol'])) {
            return (((int) $attrs['useraccountcontrol']) & 0x2) === 0;
        }
        return null;
    }

    /**
     * The AD account-expiration comparison row, or null when AD didn't return an
     * expiration at all (nothing to show). Golden side is the person's end_date;
     * AD side is the parsed expiration ('Never' or a date). The headline problem
     * is an already-expired account for someone who should be active; otherwise
     * it should line up with the golden end date (no end date ⇔ "Never").
     *
     * @param array<string,mixed>  $person
     * @param array<string,string> $attrs
     * @return array{field:string,label:string,golden:string,ad:string,state:string}|null
     */
    private static function expiryRow(array $person, array $attrs): ?array
    {
        $ad = self::accountExpiry($attrs);
        if ($ad === null) {
            return null; // AD didn't expose an expiration — no row
        }
        $goldenEnd = self::toDate((string) ($person['end_date'] ?? ''));
        $activeish = in_array((string) ($person['status'] ?? ''), ['active', 'pending'], true);
        $expiredNow = $ad !== 'Never' && $ad < gmdate('Y-m-d');

        if ($activeish && $expiredNow) {
            $state = 'differ';               // should be usable, but AD has locked it out
        } elseif ($goldenEnd === '') {
            $state = $ad === 'Never' ? 'match' : 'info';   // no golden end date to line up with
        } else {
            $state = ($ad !== 'Never' && $ad === $goldenEnd) ? 'match' : 'differ';
        }

        return ['field' => 'accountExpires', 'label' => 'Account expires', 'golden' => $goldenEnd, 'ad' => $ad, 'state' => $state];
    }

    /**
     * Normalize AD's account expiration to 'Never' or a 'Y-m-d' date. Handles
     * both a friendly `accountExpirationDate` and the raw `accountExpires`
     * Windows FILETIME (100-ns ticks since 1601; 0 or the max value = never
     * expires). Returns null when neither attribute was returned.
     *
     * @param array<string,string> $attrs
     */
    private static function accountExpiry(array $attrs): ?string
    {
        $friendly = trim((string) ($attrs['accountexpirationdate'] ?? ''));
        if ($friendly !== '') {
            return self::toDate($friendly) ?: $friendly;
        }
        if (!array_key_exists('accountexpires', $attrs)) {
            return null;
        }
        $raw = trim((string) $attrs['accountexpires']);
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw) && ctype_digit(ltrim($raw, '-'))) {
            $ft = (int) $raw;
            if ($ft <= 0 || $ft === PHP_INT_MAX || $raw === '9223372036854775807') {
                return 'Never';                       // 0 / max FILETIME = never expires
            }
            $unix = intdiv($ft, 10000000) - 11644473600;
            return $unix > 0 ? gmdate('Y-m-d', $unix) : 'Never';
        }
        return self::toDate($raw) ?: $raw;
    }

    /** A date value normalized to 'Y-m-d', or '' when empty/unparseable. */
    private static function toDate(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        $ts = strtotime($v);
        return $ts !== false ? gmdate('Y-m-d', $ts) : '';
    }

    private static function truthy(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Normalize Adaxes' property representation into a case-insensitive map of
     * name => scalar string. Handles the shapes seen across versions: a
     * `properties` list of {name,value|values}, a `properties` name=>value map,
     * or properties living directly on the object body.
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function normalizeProperties(array $data): array
    {
        $out = [];

        $props = $data['properties'] ?? null;
        if (is_array($props)) {
            // List form: [{name, value|values}, …]
            if (array_is_list($props)) {
                foreach ($props as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $name = $p['name'] ?? $p['type'] ?? null;
                    if (!is_string($name) || $name === '') {
                        continue;
                    }
                    $out[strtolower($name)] = self::scalarValue($p['value'] ?? $p['values'] ?? null);
                }
            } else {
                // Map form: {name: value, …}
                foreach ($props as $name => $value) {
                    $out[strtolower((string) $name)] = self::scalarValue($value);
                }
            }
        }

        // Top-level attributes (e.g. distinguishedName / objectGuid on the body).
        foreach ($data as $name => $value) {
            if ($name === 'properties' || is_array($value) && array_is_list($value) && isset($value[0]['name'])) {
                continue;
            }
            $key = strtolower((string) $name);
            if (!array_key_exists($key, $out) && (is_scalar($value) || is_array($value))) {
                $out[$key] = self::scalarValue($value);
            }
        }

        return $out;
    }

    /** Flatten a property value (scalar, single-element array, or multi-value) to a string. */
    private static function scalarValue(mixed $value): string
    {
        if (is_array($value)) {
            $flat = array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $value);
            return implode(', ', array_filter($flat, static fn($v) => $v !== ''));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Pull the first object out of a search response, tolerating the common
     * envelopes: {objects:[…]}, {value:[…]}, {items:[…]}, or a bare list.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private static function firstSearchHit(array $data): ?array
    {
        foreach (['objects', 'value', 'items', 'results'] as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && isset($data[$key][0]) && is_array($data[$key][0])) {
                return $data[$key][0];
            }
        }
        if (array_is_list($data) && isset($data[0]) && is_array($data[0])) {
            return $data[0];
        }
        return null;
    }

    /**
     * Resolve the security token sent as `Adm-Authorization`. A static
     * ADAXES_TOKEN wins; otherwise run the legacy two-step handshake with the
     * service username/password (create session → obtain token) and cache the
     * result for this instance. Returns null and sets $authError on failure.
     */
    private function authToken(): ?string
    {
        if ($this->token !== '') {
            return $this->token;
        }
        if ($this->resolvedToken !== null) {
            return $this->resolvedToken;
        }
        if ($this->authAttempted) {
            return null; // don't retry a failed handshake repeatedly within a request
        }
        $this->authAttempted = true;

        if ($this->username === '' || $this->password === '') {
            $this->authError = 'no ADAXES_TOKEN and no username/password';
            return null;
        }

        // 1) Create an authentication session (POST credentials).
        $sessUrl = $this->baseUrl . '/' . $this->sessionPath;
        $resp = ($this->fetch)('POST', $sessUrl, self::jsonHeaders(), (string) json_encode(['username' => $this->username, 'password' => $this->password]));
        $this->debugLog('POST', $sessUrl, $resp['status'] ?? 0, '(authSessions/create — body redacted)');
        $session = $this->decode($resp);
        if (!$session['ok']) {
            $this->authError = 'session create failed (HTTP ' . ($resp['status'] ?? 0) . ')';
            return null;
        }
        $sessionId = $session['data']['sessionId'] ?? $session['data']['id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            $this->authError = 'no sessionId in authSessions/create response';
            return null;
        }
        $this->resolvedSessionId = $sessionId;

        // 2) Exchange the session for a security token.
        $tokUrl = $this->baseUrl . '/' . $this->tokenPath;
        $resp2 = ($this->fetch)('POST', $tokUrl, self::jsonHeaders(), (string) json_encode(['sessionId' => $sessionId]));
        $this->debugLog('POST', $tokUrl, $resp2['status'] ?? 0, '(auth — token redacted)');
        $tok = $this->decode($resp2);
        if (!$tok['ok']) {
            $this->authError = 'token request failed (HTTP ' . ($resp2['status'] ?? 0) . ')';
            return null;
        }
        $token = $tok['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->authError = 'no token in auth response';
            return null;
        }
        return $this->resolvedToken = $token;
    }

    /** Headers for the unauthenticated handshake POSTs. @return array<string,string> */
    private static function jsonHeaders(): array
    {
        return ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    /**
     * Tear down a handshake-minted session + token (DELETE the token, then the
     * session) so they don't linger until auto-expiry. Best-effort and idempotent
     * — a static ADAXES_TOKEN minted no session, so this no-ops. Resets the
     * cached auth so a later call re-authenticates cleanly. Token *renewal* (the
     * PATCH in the SDK sample) is unnecessary here: each verification is a short
     * single burst that finishes well within the token lifetime.
     */
    private function endSession(): void
    {
        if ($this->resolvedSessionId === null) {
            return;
        }
        $sessionId = $this->resolvedSessionId;
        $token = $this->resolvedToken;
        // Reset first so we never double-clean and a re-entry re-handshakes.
        $this->resolvedSessionId = null;
        $this->resolvedToken = null;
        $this->authAttempted = false;

        try {
            if ($token !== null) {
                $tokUrl = $this->baseUrl . '/' . $this->tokenPath . '?token=' . rawurlencode($token);
                ($this->fetch)('DELETE', $tokUrl, ['Adm-Authorization' => $token, 'Accept' => 'application/json'], null);
            }
            // The terminate endpoint is the sessions collection (sans "/create").
            $sessionsPath = (string) preg_replace('#/create$#', '', $this->sessionPath);
            $sessUrl = $this->baseUrl . '/' . $sessionsPath . '?id=' . rawurlencode($sessionId);
            ($this->fetch)('DELETE', $sessUrl, ['Accept' => 'application/json'], null);
        } catch (\Throwable) {
            // Cleanup is best-effort; the session/token will auto-expire anyway.
        }
    }

    /**
     * Decode an HTTP response into JSON, classifying failures into a message.
     *
     * @param array{status:int,body:string}|null $resp
     * @return array{ok:bool, error:?string, data:array<string,mixed>}
     */
    private function decode(?array $resp): array
    {
        if ($resp === null) {
            return ['ok' => false, 'error' => 'Adaxes is unreachable.', 'data' => []];
        }
        $status = $resp['status'] ?? 0;
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => 'Adaxes rejected the credentials (HTTP ' . $status . ') — check ADAXES_TOKEN (or username/password).', 'data' => []];
        }
        if ($status >= 300 && $status < 400) {
            // A redirect is usually one of two things: a wrong path (the REST API
            // lives under {base}/api — a missing api/ segment gets redirected) or
            // an unauthenticated request bounced to a login. The Location header
            // (appended by request()) tells which.
            return ['ok' => false, 'error' => 'Adaxes redirected the request (HTTP ' . $status . ') — likely a wrong path (the REST API is under {base}/api) or an unauthenticated request. Check ADAXES_BASE_URL / ADAXES_OBJECTS_PATH and ADAXES_TOKEN.', 'data' => []];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Adaxes returned HTTP ' . $status . '.', 'data' => []];
        }
        $data = json_decode((string) ($resp['body'] ?? ''), true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Adaxes returned invalid JSON.', 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => $data];
    }

    /**
     * Perform a request and decode it, annotating any error with the method +
     * URL path (no query string, so no PII) so a misconfigured endpoint is
     * obvious, and writing an optional debug line. The Basic credentials live in
     * a header, never the URL, so the path is safe to surface.
     *
     * @return array{ok:bool, error:?string, data:array<string,mixed>, status:int}
     */
    private function request(string $method, string $url, ?string $body = null): array
    {
        $token = $this->authToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Adaxes authentication failed: ' . ($this->authError ?? 'unknown') . '.', 'data' => [], 'status' => 0];
        }
        $headers = ['Adm-Authorization' => $token, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $resp = ($this->fetch)($method, $url, $headers, $body);
        $decoded = $this->decode($resp);
        $status = $resp['status'] ?? 0;
        $location = is_array($resp) ? ($resp['location'] ?? null) : null;
        if (!$decoded['ok'] && $status > 0 && $decoded['error'] !== null) {
            $decoded['error'] .= ' [' . $method . ' ' . self::urlPath($url) . ']';
            if ($location !== null && $location !== '') {
                $decoded['error'] .= ' (redirected to ' . self::urlPath((string) $location) . ')';
            }
        }
        $this->debugLog($method, $url, $status, $resp === null ? null : (string) ($resp['body'] ?? ''), $location);
        $decoded['status'] = $status;
        return $decoded;
    }

    /** The URL without its query string (drops the search filter / PII). */
    private static function urlPath(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false ? $url : substr($url, 0, $q);
    }

    /**
     * Append a request/response line to the Adaxes debug log when ADAXES_DEBUG is
     * on. Logs the full URL + a response snippet so an operator can see exactly
     * what was sent and returned. Never logs the Authorization header. NOTE: the
     * snippet can contain directory data (PII) and the URL carries the search
     * filter — enable only while troubleshooting, then turn it back off.
     */
    private function debugLog(string $method, string $url, int $status, ?string $body, ?string $location = null): void
    {
        if (!Config::bool('ADAXES_DEBUG', false)) {
            return;
        }
        $snippet = $body === null
            ? '(no response — transport failure)'
            : substr((string) preg_replace('/\s+/', ' ', $body), 0, 500);
        $loc = ($location !== null && $location !== '') ? ' -> Location: ' . $location : '';
        $line = sprintf("[%s] %s %s -> HTTP %d%s | %s", gmdate('c'), $method, $url, $status, $loc, $snippet);

        // Write to the configured file; if that fails (dir missing / not writable
        // by php-fpm) fall back to the PHP error log so debug output is never lost.
        $path = (string) Config::get('ADAXES_LOG', '/var/idm/adaxes_debug.log');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[idm][adaxes] ' . $line);
        }
    }

    /**
     * Real HTTP transport. Short timeout; returns the status + body, or null on a
     * transport-level failure (DNS, connect, timeout). Honors an internal CA
     * bundle (ADAXES_CA_FILE); TLS verification stays ON unless an operator
     * explicitly disables it (ADAXES_VERIFY_TLS=false) for a self-signed host.
     *
     * @param array<string,string> $headers
     * @return array{status:int,body:string}|null
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $body): ?array
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $verifyTls = Config::bool('ADAXES_VERIFY_TLS', true);
        $caFile = (string) Config::get('ADAXES_CA_FILE', '');

        $ctx = stream_context_create([
            'http' => [
                'method'         => $method,
                'timeout'        => $this->timeout,
                'ignore_errors'  => true,
                'follow_location' => 0,   // capture the auth redirect instead of chasing a login page
                'max_redirects'  => 0,
                'header'         => $headerLines,
                'content'        => $body ?? '',
            ],
            'ssl' => array_filter([
                'verify_peer'      => $verifyTls,
                'verify_peer_name' => $verifyTls,
                'cafile'           => $caFile !== '' ? $caFile : null,
            ], static fn($v) => $v !== null),
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $headers = $http_response_header ?? [];
        return [
            'status'   => self::statusFromHeaders($headers),
            'body'     => $raw,
            'location' => self::headerValue($headers, 'Location'),
        ];
    }

    /** First value of the named response header (case-insensitive), or null. */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name) . ':';
        foreach ($headers as $line) {
            $line = (string) $line;
            if (str_starts_with(strtolower($line), $needle)) {
                return trim(substr($line, strlen($needle)));
            }
        }
        return null;
    }

    /** Parse the numeric status code out of the $http_response_header lines. */
    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                return (int) $m[1]; // last status line wins (handles redirects)
            }
        }
        return 0;
    }

    /**
     * @return Envelope
     * @param array<string,string> $attributes
     * @param array<int,array{field:string,label:string,golden:string,ad:string,state:string}> $comparison
     */
    private static function envelope(
        bool $ok,
        bool $configured,
        bool $found = false,
        ?string $error = null,
        ?string $by = null,
        ?string $identifier = null,
        array $attributes = [],
        array $comparison = [],
        ?string $guid = null,
    ): array {
        return [
            'ok' => $ok, 'error' => $error, 'configured' => $configured,
            'found' => $found, 'by' => $by, 'identifier' => $identifier,
            'attributes' => $attributes, 'comparison' => $comparison, 'guid' => $guid,
        ];
    }

    /**
     * Pull a normalized objectGUID out of the AD attributes, if present and
     * well-formed. Tolerates the brace-wrapped form ({GUID}); returns null for a
     * missing or non-GUID value so we never store junk in the crosswalk.
     *
     * @param array<string,string> $attrs
     */
    private static function extractGuid(array $attrs): ?string
    {
        $raw = trim((string) ($attrs['objectguid'] ?? ''));
        $raw = trim($raw, '{}');
        return preg_match('/^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$/', $raw) ? $raw : null;
    }
}
