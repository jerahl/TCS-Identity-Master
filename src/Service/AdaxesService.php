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
    /** Shared Adaxes auth + HTTP transport (token/handshake, TLS, injectable $fetch). */
    use AdaxesHttp;

    private string $objectsPath;
    private string $objectParam;
    private string $searchPath;
    private string $employeeIdAttr;

    /** @var list<string> */
    private array $properties;

    /** Default AD attributes pulled for the comparison panel. */
    private const DEFAULT_PROPERTIES = [
        'objectGUID', 'sAMAccountName', 'userPrincipalName', 'mail', 'displayName',
        'distinguishedName', 'accountDisabled', 'userAccountControl',
        'accountExpires', 'accountExpirationDate',
        'department', 'title', 'whenChanged',
        'physicalDeliveryOfficeName', 'description', 'info',
        // sn + employeeID let the reconciler cross-check a returning-employee match
        // (employee number + surname) before re-enabling an existing account.
        'sn', 'employeeID',
        // givenName + cn feed the edit phase's name-change handling: givenName for
        // the immediate attribute push (comparing against an unfetched attribute
        // would read as always-drifted), cn for the object rename that keeps AD's
        // "Full Name" / DN aligned with a changed legal name.
        'givenName', 'cn',
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
     * Resolve a group by its cn/name to a directory identifier the group-member
     * REST calls accept (its distinguishedName, else objectGUID). The reconciler
     * knows groups by name (All-Faculty, CO-Everyone, …) but the API needs a
     * DN/GUID, so this bridges the two.
     *
     * @return array{ok:bool, error:?string, found:bool, id:?string, dn:?string, guid:?string}
     */
    public function findGroup(string $cn): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Adaxes is not configured.', 'found' => false, 'id' => null, 'dn' => null, 'guid' => null];
        }
        $cn = trim($cn);
        if ($cn === '') {
            return ['ok' => true, 'error' => null, 'found' => false, 'id' => null, 'dn' => null, 'guid' => null];
        }

        // Match a Group whose cn OR sAMAccountName equals the name.
        $body = [
            'criteria' => [
                'objectTypes' => [[
                    'type'  => 'Group',
                    'items' => [
                        'type'            => 1,
                        'logicalOperator' => 2, // OR
                        'items'           => [
                            ['type' => 0, 'property' => 'cn', 'operator' => 'eq', 'values' => [['type' => 2, 'value' => $cn]], 'valueLogicalOperator' => 0],
                            ['type' => 0, 'property' => 'sAMAccountName', 'operator' => 'eq', 'values' => [['type' => 2, 'value' => $cn]], 'valueLogicalOperator' => 0],
                        ],
                    ],
                ]],
            ],
            'select' => ['properties' => 'distinguishedName,objectGUID,cn,sAMAccountName'],
        ];

        try {
            $res = $this->request('POST', $this->baseUrl . '/' . $this->searchPath, (string) json_encode($body));
            if (!$res['ok']) {
                return ['ok' => false, 'error' => $res['error'], 'found' => false, 'id' => null, 'dn' => null, 'guid' => null];
            }
            $first = self::firstSearchHit($res['data']);
            if ($first === null) {
                return ['ok' => true, 'error' => null, 'found' => false, 'id' => null, 'dn' => null, 'guid' => null];
            }
            $attrs = self::normalizeProperties($first);
            $dn = trim((string) ($attrs['distinguishedname'] ?? ''));
            $guid = self::extractGuid($attrs);
            $id = $dn !== '' ? $dn : $guid;
            return ['ok' => true, 'error' => null, 'found' => $id !== null && $id !== '', 'id' => $id, 'dn' => $dn ?: null, 'guid' => $guid];
        } finally {
            $this->endSession();
        }
    }

    /**
     * The group DNs a directory object is a direct member of (`memberOf`). Kept
     * separate from getObject() because memberOf is multi-valued and each value
     * is a DN (full of commas), which the scalar property flattening would
     * corrupt — this returns the raw list. Requests only memberOf, so it's cheap
     * enough for the group reconciler to call per person.
     *
     * @return array{ok:bool, error:?string, found:bool, groups:list<string>}
     */
    public function memberOf(string $idOrDn): array
    {
        $res = $this->attributeValues($idOrDn, 'memberOf');
        return ['ok' => $res['ok'], 'error' => $res['error'], 'found' => $res['found'], 'groups' => $res['values']];
    }

    /**
     * The raw values of a (possibly multi-valued) attribute on a directory
     * object, without the scalar comma-flattening — for attributes whose values
     * contain commas (memberOf DNs, proxyAddresses). Requests only that attribute.
     *
     * @return array{ok:bool, error:?string, found:bool, values:list<string>}
     */
    public function attributeValues(string $idOrDn, string $attribute): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Adaxes is not configured.', 'found' => false, 'values' => []];
        }
        try {
            $url = $this->baseUrl . '/' . $this->objectsPath
                 . '?' . $this->objectParam . '=' . rawurlencode($idOrDn)
                 . '&properties=' . rawurlencode($attribute);
            $res = $this->request('GET', $url);
            if (!$res['ok']) {
                if ($res['status'] === 404) {
                    return ['ok' => true, 'error' => null, 'found' => false, 'values' => []];
                }
                return ['ok' => false, 'error' => $res['error'], 'found' => false, 'values' => []];
            }
            return ['ok' => true, 'error' => null, 'found' => true, 'values' => self::rawMultiValue($res['data'], $attribute)];
        } finally {
            $this->endSession();
        }
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
     * Whether a verify() envelope's live AD account is enabled, from the returned
     * attributes (the `accountDisabled` flag or the userAccountControl 0x2 bit).
     * Null when neither attribute was present — the reconciler treats "unknown" as
     * "don't act" so it never disables on a partial read.
     *
     * @param Envelope $envelope
     */
    public static function accountEnabledFromEnvelope(array $envelope): ?bool
    {
        $attrs = is_array($envelope['attributes'] ?? null) ? $envelope['attributes'] : [];
        return self::accountEnabled($attrs);
    }

    /**
     * The live AD account expiration from a verify() envelope: 'Never', a 'Y-m-d'
     * date, or null when AD returned no expiration attribute at all. Mirrors
     * accountEnabledFromEnvelope so the reconciler can read the current expiry
     * without reaching into the raw attribute shape.
     *
     * @param Envelope $envelope
     */
    public static function accountExpiryFromEnvelope(array $envelope): ?string
    {
        $attrs = is_array($envelope['attributes'] ?? null) ? $envelope['attributes'] : [];
        return self::accountExpiry($attrs);
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
        foreach ($sourceIds as $row) {
            if (strtolower((string) ($row['system'] ?? '')) !== 'ad') {
                continue;
            }
            // Only an ACTIVE AD link resolves a GUID. A deactivated row (e.g. after
            // an unlink, or one cleanup_ad_ids marked bad) must NOT keep matching the
            // old account — otherwise the person can never be correlated to a
            // different account.
            if (empty($row['is_active'])) {
                continue;
            }
            $key = trim((string) ($row['source_key'] ?? ''));
            if ($key !== '') {
                return $key;
            }
        }
        return null;
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

    /**
     * Pull a named multi-valued property out of a response as its RAW list of
     * string values (NOT comma-joined — the values may be DNs). Tolerates the
     * list-form ([{name,value|values}]) and map-form ({name: value|values})
     * property shapes and a top-level attribute. Case-insensitive on the name.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private static function rawMultiValue(array $data, string $name): array
    {
        $wanted = strtolower($name);
        $collect = static function (mixed $value): array {
            if ($value === null) {
                return [];
            }
            $items = is_array($value) && array_is_list($value) ? $value : [$value];
            $out = [];
            foreach ($items as $v) {
                if (is_scalar($v) && trim((string) $v) !== '') {
                    $out[] = trim((string) $v);
                }
            }
            return $out;
        };

        $props = $data['properties'] ?? null;
        if (is_array($props)) {
            if (array_is_list($props)) {
                foreach ($props as $p) {
                    if (is_array($p) && strtolower((string) ($p['name'] ?? $p['type'] ?? '')) === $wanted) {
                        return $collect($p['value'] ?? $p['values'] ?? null);
                    }
                }
            } else {
                foreach ($props as $k => $v) {
                    if (strtolower((string) $k) === $wanted) {
                        return $collect($v);
                    }
                }
            }
        }
        foreach ($data as $k => $v) {
            if (strtolower((string) $k) === $wanted) {
                return $collect($v);
            }
        }
        return [];
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
