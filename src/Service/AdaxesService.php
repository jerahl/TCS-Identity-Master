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
 * @phpstan-type Envelope array{ok:bool, error:?string, configured:bool, found:bool, by:?string, identifier:?string, attributes:array<string,string>, comparison:array<int,array{field:string,label:string,golden:string,ad:string,state:string}>}
 * @phpstan-type HttpResponse array{status:int, body:string}
 */
final class AdaxesService
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private int $timeout;
    private string $objectsPath;
    private string $searchPath;
    private string $employeeIdAttr;
    /** @var list<string> */
    private array $properties;
    /** @var callable(string,string,array<string,string>,?string):?array{status:int,body:string} */
    private $fetch;

    /** Default AD attributes pulled for the comparison panel. */
    private const DEFAULT_PROPERTIES = [
        'sAMAccountName', 'userPrincipalName', 'mail', 'displayName',
        'distinguishedName', 'accountDisabled', 'userAccountControl',
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
    ) {
        $this->baseUrl        = rtrim($baseUrl ?? (string) Config::get('ADAXES_BASE_URL', ''), '/');
        $this->username       = $username ?? (string) Config::get('ADAXES_USERNAME', '');
        $this->password       = $password ?? (string) Config::get('ADAXES_PASSWORD', '');
        $this->timeout        = $timeout ?? max(1, (int) Config::get('ADAXES_TIMEOUT', '5'));
        $this->objectsPath    = trim($objectsPath ?? (string) Config::get('ADAXES_OBJECTS_PATH', 'directoryObjects'), '/');
        $this->searchPath     = trim($searchPath ?? (string) Config::get('ADAXES_SEARCH_PATH', 'directorySearcher/search'), '/');
        $this->employeeIdAttr = trim($employeeIdAttr ?? (string) Config::get('ADAXES_EMPLOYEE_ID_ATTR', 'employeeID')) ?: 'employeeID';

        $configured = $properties ?? array_values(array_filter(array_map(
            'trim',
            explode(',', (string) Config::get('ADAXES_PROPERTIES', ''))
        )));
        $this->properties = $configured !== [] ? $configured : self::DEFAULT_PROPERTIES;

        $this->fetch = $fetch ?? fn(string $method, string $url, array $headers, ?string $body): ?array
            => $this->httpRequest($method, $url, $headers, $body);
    }

    /** Live verification is available only when base URL + service credentials are set. */
    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->username !== '' && $this->password !== '';
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
            return self::envelope(ok: false, configured: false, error: 'Adaxes is not configured (set ADAXES_BASE_URL, ADAXES_USERNAME, ADAXES_PASSWORD).');
        }

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
        );
    }

    /**
     * GET a directory object by DN or objectGUID, requesting our property set.
     *
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function getObject(string $idOrDn): array
    {
        $url = $this->baseUrl . '/' . $this->objectsPath . '/' . rawurlencode($idOrDn)
             . '?properties=' . rawurlencode(implode(',', $this->properties));

        $resp = ($this->fetch)('GET', $url, $this->headers(), null);
        $decoded = $this->decode($resp);
        if (!$decoded['ok']) {
            // A 404 here means the object id is stale (deleted/renamed), not an outage.
            if (($resp['status'] ?? 0) === 404) {
                return ['ok' => true, 'error' => null, 'found' => false, 'attributes' => []];
            }
            return ['ok' => false, 'error' => $decoded['error'], 'found' => false, 'attributes' => []];
        }

        return ['ok' => true, 'error' => null, 'found' => true, 'attributes' => self::normalizeProperties($decoded['data'])];
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
     * Run a directory search for an OR of the given attribute=value criteria and
     * return the first hit. Best-effort fallback when no objectGUID is on file —
     * the search path/shape varies by Adaxes version, hence ADAXES_SEARCH_PATH is
     * configurable.
     *
     * @param array<int,array{attr:string,value:string}> $criteria  non-empty
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function searchByCriteria(array $criteria): array
    {
        $clauses = '';
        foreach ($criteria as $c) {
            $clauses .= '(' . $c['attr'] . '=' . self::escapeLdap($c['value']) . ')';
        }
        // Single criterion needs no |; multiple are OR'd so any one matches.
        $filter = count($criteria) > 1 ? '(|' . $clauses . ')' : $clauses;

        $url = $this->baseUrl . '/' . $this->searchPath
             . '?filter=' . rawurlencode($filter)
             . '&properties=' . rawurlencode(implode(',', $this->properties));

        $resp = ($this->fetch)('GET', $url, $this->headers(), null);
        $decoded = $this->decode($resp);
        if (!$decoded['ok']) {
            return ['ok' => false, 'error' => $decoded['error'], 'found' => false, 'attributes' => []];
        }

        $first = self::firstSearchHit($decoded['data']);
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

    /** Escape the characters that are special inside an LDAP filter value (RFC 4515). */
    private static function escapeLdap(string $value): string
    {
        return strtr($value, [
            '\\' => '\\5c', '*' => '\\2a', '(' => '\\28', ')' => '\\29', "\0" => '\\00',
        ]);
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            'Accept'        => 'application/json',
        ];
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
            return ['ok' => false, 'error' => 'Adaxes rejected the service credentials (HTTP ' . $status . ').', 'data' => []];
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
                'method'        => $method,
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'header'        => $headerLines,
                'content'       => $body ?? '',
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

        return ['status' => self::statusFromHeaders($http_response_header ?? []), 'body' => $raw];
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
    ): array {
        return [
            'ok' => $ok, 'error' => $error, 'configured' => $configured,
            'found' => $found, 'by' => $by, 'identifier' => $identifier,
            'attributes' => $attributes, 'comparison' => $comparison,
        ];
    }
}
