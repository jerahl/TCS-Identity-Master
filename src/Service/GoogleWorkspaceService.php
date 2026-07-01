<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * Read/WRITE client for the Google Workspace **Admin SDK Directory API** — the
 * third leg of identity provisioning, running DIRECTLY from the golden record
 * and BYPASSING OneSync. Where AdaxesService only verifies AD (read-only), this
 * service both correlates (finds the existing Google account for a person, the
 * way OneSync correlation does) AND writes: create, edit, suspend, restore.
 *
 * Design mirrors AdaxesService: it is config-gated (does nothing unless the
 * GOOGLE_* env is set AND GOOGLE_DIRECT_ENABLED is on), the HTTP call is
 * injectable (so the logic is unit-tested with no live Google), and it NEVER
 * throws — every path returns a result envelope. "Disable" is a SUSPEND
 * (reversible), never a delete, matching the app's status-changes-not-deletes
 * philosophy and OneSync's Disable/Enable actions.
 *
 * Auth is a service account with **domain-wide delegation**: a short-lived JWT
 * assertion (RS256) is signed locally with the SA private key and exchanged at
 * Google's OAuth2 token endpoint for an access token that impersonates an admin
 * subject (GOOGLE_ADMIN_SUBJECT). The JWT is signed with native openssl_sign()
 * (ext-openssl) so this service — like AdaxesService — needs no vendored
 * dependency and works on a bare checkout.
 *
 * @phpstan-type Envelope array{ok:bool, error:?string, configured:bool, found:bool, auto:bool, by:?string, identifier:?string, attributes:array<string,string>, comparison:array<int,array{field:string,label:string,golden:string,google:string,state:string}>, googleId:?string, primaryEmail:?string, suspended:?bool}
 * @phpstan-type WriteResult array{ok:bool, error:?string, action:string, googleId:?string, primaryEmail:?string, suspended:?bool, attributes:array<string,string>}
 * @phpstan-type HttpResponse array{status:int, body:string}
 */
final class GoogleWorkspaceService
{
    /** Directory API + OAuth2 endpoints (host is fixed; overridable for tests). */
    private const DEFAULT_API_BASE = 'https://admin.googleapis.com/admin/directory/v1';
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const DEFAULT_SCOPES = 'https://www.googleapis.com/auth/admin.directory.user';

    private bool $enabled;
    private string $apiBase;
    private string $tokenUri;
    private string $scopes;
    private string $clientEmail;
    private string $privateKey;
    private string $adminSubject;
    private string $customer;
    private string $domain;
    private int $timeout;

    /** Access token resolved from the JWT handshake (or a test-injected static token), cached per instance. */
    private ?string $accessToken;
    private bool $authAttempted = false;
    private ?string $authError = null;

    /** @var callable(string,string,array<string,string>,?string):?array{status:int,body:string} */
    private $fetch;
    /** @var callable(string):?string  ($signingInput) → raw RS256 signature bytes, or null on failure */
    private $signer;

    /**
     * @param callable(string,string,array<string,string>,?string):?array{status:int,body:string}|null $fetch
     *        ($method, $url, $headers, $body) → ['status'=>int,'body'=>string], or null on transport failure.
     * @param callable(string):?string|null $signer  ($input) → raw signature; defaults to openssl_sign(RS256).
     */
    public function __construct(
        ?bool $enabled = null,
        ?string $clientEmail = null,
        ?string $privateKey = null,
        ?string $adminSubject = null,
        ?string $customer = null,
        ?string $domain = null,
        ?int $timeout = null,
        ?callable $fetch = null,
        ?callable $signer = null,
        ?string $accessToken = null,
        ?string $apiBase = null,
        ?string $tokenUri = null,
        ?string $scopes = null,
    ) {
        $this->enabled = $enabled ?? Config::bool('GOOGLE_DIRECT_ENABLED', false);

        // Service-account credentials: an explicit client email + PEM key win;
        // otherwise load them from the SA JSON key file / inline JSON.
        [$saEmail, $saKey] = self::loadServiceAccount();
        $this->clientEmail  = trim($clientEmail ?? (string) Config::get('GOOGLE_SA_CLIENT_EMAIL', $saEmail));
        $this->privateKey   = trim($privateKey ?? (string) Config::get('GOOGLE_SA_PRIVATE_KEY', $saKey));
        $this->adminSubject = trim($adminSubject ?? (string) Config::get('GOOGLE_ADMIN_SUBJECT', ''));
        $this->customer     = trim($customer ?? (string) Config::get('GOOGLE_CUSTOMER', 'my_customer')) ?: 'my_customer';
        $this->domain       = trim($domain ?? (string) Config::get('GOOGLE_DOMAIN', ''));
        $this->timeout      = $timeout ?? max(1, (int) Config::get('GOOGLE_TIMEOUT', '10'));

        $this->apiBase  = rtrim($apiBase ?? (string) Config::get('GOOGLE_API_BASE', self::DEFAULT_API_BASE), '/');
        $this->tokenUri = trim($tokenUri ?? (string) Config::get('GOOGLE_TOKEN_URI', self::DEFAULT_TOKEN_URI));
        $this->scopes   = trim($scopes ?? (string) Config::get('GOOGLE_SCOPES', self::DEFAULT_SCOPES)) ?: self::DEFAULT_SCOPES;

        // A test-injected access token skips the whole JWT handshake.
        $this->accessToken = $accessToken !== null && $accessToken !== '' ? $accessToken : null;

        $this->fetch = $fetch ?? fn(string $method, string $url, array $headers, ?string $body): ?array
            => $this->httpRequest($method, $url, $headers, $body);
        $this->signer = $signer ?? fn(string $input): ?string => $this->opensslSign($input);
    }

    /**
     * Direct Google provisioning is available only when the feature flag is on
     * AND we can authenticate — either a test-injected access token, or the SA
     * client email + private key + an admin subject to impersonate.
     */
    public function configured(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->accessToken !== null) {
            return true;
        }
        return $this->clientEmail !== '' && $this->privateKey !== '' && $this->adminSubject !== '';
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    // ---- correlation (read) -------------------------------------------------

    /**
     * Correlate a person to their existing Google account, OneSync-style. Tiers,
     * strongest key first (first hit wins) — the same shape as the import Matcher
     * and AdaxesService's lookup order:
     *   1. crosswalk Google id (person_source_id where system='google')  → AUTO
     *   2. primaryEmail == golden email (fallback UPN)                    → AUTO
     *   3. externalId == employee_id                                      → AUTO
     *   4. givenName + familyName                                         → REVIEW (never auto)
     *
     * `auto` is true only for the strong-key tiers (1–3); a name-only hit sets
     * auto=false so the UI/batch treats it as a suggestion to confirm, never an
     * automatic link (mirrors the Matcher's "name-only never auto-links" rule).
     *
     * @param array<string,mixed> $person     golden record (email, upn, employee_id, first_name, last_name, status, …)
     * @param array<int,array<string,mixed>> $sourceIds  person_source_id rows (system, source_key, is_active)
     * @return Envelope
     */
    public function correlate(array $person, array $sourceIds): array
    {
        if (!$this->configured()) {
            return self::envelope(ok: false, configured: false,
                error: 'Direct Google provisioning is off (set GOOGLE_DIRECT_ENABLED=true plus the GOOGLE_SA_* service-account credentials and GOOGLE_ADMIN_SUBJECT).');
        }

        // Tier 1 — the stable crosswalk id.
        $googleId = self::googleSourceKey($sourceIds);
        if ($googleId !== null) {
            $res = $this->getUser($googleId);
            if (!$res['ok']) {
                return self::envelope(ok: false, configured: true, error: $res['error'], by: 'id', identifier: $googleId);
            }
            if ($res['found']) {
                return $this->foundEnvelope($person, $res['attributes'], 'id', $googleId, auto: true);
            }
            // A stale/renamed id falls through to the attribute lookups below.
        }

        // Tier 2 — primary email (golden email, then UPN).
        foreach (['email', 'upn'] as $field) {
            $value = trim((string) ($person[$field] ?? ''));
            if ($value === '') {
                continue;
            }
            $res = $this->getUser($value);
            if (!$res['ok']) {
                return self::envelope(ok: false, configured: true, error: $res['error'], by: 'email', identifier: $value);
            }
            if ($res['found']) {
                return $this->foundEnvelope($person, $res['attributes'], 'email', $value, auto: true);
            }
        }

        // Tier 3 — externalId == employee id (strong key).
        $employeeId = trim((string) ($person['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $res = $this->searchUsers("externalId=" . self::quote($employeeId));
            if (!$res['ok']) {
                return self::envelope(ok: false, configured: true, error: $res['error'], by: 'externalId', identifier: $employeeId);
            }
            if ($res['found']) {
                return $this->foundEnvelope($person, $res['attributes'], 'externalId', $employeeId, auto: true);
            }
        }

        // Tier 4 — name only. NEVER auto-links: returned as a review suggestion.
        $first = trim((string) ($person['first_name'] ?? ''));
        $last = trim((string) ($person['last_name'] ?? ''));
        if ($first !== '' && $last !== '') {
            $query = "givenName=" . self::quote($first) . " familyName=" . self::quote($last);
            $res = $this->searchUsers($query);
            if (!$res['ok']) {
                return self::envelope(ok: false, configured: true, error: $res['error'], by: 'name', identifier: "{$first} {$last}");
            }
            if ($res['found']) {
                return $this->foundEnvelope($person, $res['attributes'], 'name', "{$first} {$last}", auto: false);
            }
        }

        return self::envelope(ok: true, configured: true, found: false);
    }

    /**
     * GET a single user by id or primaryEmail. A 404 is a clean not-found (so the
     * caller can fall through to the next correlation tier), not an error.
     *
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function getUser(string $userKey): array
    {
        $url = $this->apiBase . '/users/' . rawurlencode($userKey) . '?projection=full';
        $res = $this->request('GET', $url);
        if (!$res['ok']) {
            if ($res['status'] === 404) {
                return ['ok' => true, 'error' => null, 'found' => false, 'attributes' => []];
            }
            return ['ok' => false, 'error' => $res['error'], 'found' => false, 'attributes' => []];
        }
        return ['ok' => true, 'error' => null, 'found' => true, 'attributes' => self::normalizeUser($res['data'])];
    }

    /**
     * Search the directory with a Directory API `query` string and return the
     * first hit. Used for the externalId and name correlation tiers.
     *
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function searchUsers(string $query): array
    {
        $url = $this->apiBase . '/users'
             . '?customer=' . rawurlencode($this->customer)
             . '&maxResults=2&projection=full'
             . '&query=' . rawurlencode($query);
        $res = $this->request('GET', $url);
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'found' => false, 'attributes' => []];
        }
        $users = $res['data']['users'] ?? null;
        if (!is_array($users) || !isset($users[0]) || !is_array($users[0])) {
            return ['ok' => true, 'error' => null, 'found' => false, 'attributes' => []];
        }
        return ['ok' => true, 'error' => null, 'found' => true, 'attributes' => self::normalizeUser($users[0])];
    }

    // ---- writes -------------------------------------------------------------

    /**
     * Create a Google user from the golden record. Requires a golden primaryEmail
     * (the app never invents an address) — the caller must have verified there is
     * no existing account (correlate() found nothing) to avoid duplicates.
     *
     * @param array<string,mixed> $person   golden record
     * @param string|null $orgUnitPath       Google OU path (from school.google_ou); defaults to '/'
     * @return WriteResult
     */
    public function createUser(array $person, ?string $orgUnitPath = null): array
    {
        if (!$this->configured()) {
            return self::writeFail('create', 'Direct Google provisioning is off.');
        }
        $email = trim((string) ($person['email'] ?? ''));
        if ($email === '') {
            return self::writeFail('create', 'No golden email on file — create in Google requires the primary email to be set first.');
        }

        $body = self::buildCreateBody($person, $email, $orgUnitPath);
        $res = $this->request('POST', $this->apiBase . '/users', (string) json_encode($body));
        if (!$res['ok']) {
            return self::writeFail('create', $res['error']);
        }
        return self::writeOk('create', self::normalizeUser($res['data']));
    }

    /**
     * Push golden-record changes to an existing Google account (name, OU,
     * externalId). Uses PATCH so only the supplied fields change.
     *
     * @param array<string,mixed> $person
     * @return WriteResult
     */
    public function updateUser(string $userKey, array $person, ?string $orgUnitPath = null): array
    {
        if (!$this->configured()) {
            return self::writeFail('edit', 'Direct Google provisioning is off.');
        }
        $body = self::buildUpdateBody($person, $orgUnitPath);
        if ($body === []) {
            return self::writeFail('edit', 'Nothing to update.');
        }
        $res = $this->request('PATCH', $this->apiBase . '/users/' . rawurlencode($userKey), (string) json_encode($body));
        if (!$res['ok']) {
            return self::writeFail('edit', $res['error']);
        }
        return self::writeOk('edit', self::normalizeUser($res['data']));
    }

    /** Suspend (disable) a Google account. Reversible via restoreUser(). @return WriteResult */
    public function suspendUser(string $userKey): array
    {
        return $this->setSuspended($userKey, true, 'disable');
    }

    /** Restore (enable) a suspended Google account. @return WriteResult */
    public function restoreUser(string $userKey): array
    {
        return $this->setSuspended($userKey, false, 'enable');
    }

    private function setSuspended(string $userKey, bool $suspended, string $action): array
    {
        if (!$this->configured()) {
            return self::writeFail($action, 'Direct Google provisioning is off.');
        }
        $res = $this->request('PATCH', $this->apiBase . '/users/' . rawurlencode($userKey), (string) json_encode(['suspended' => $suspended]));
        if (!$res['ok']) {
            return self::writeFail($action, $res['error']);
        }
        return self::writeOk($action, self::normalizeUser($res['data']));
    }

    // ---- comparison (golden vs live) ---------------------------------------

    /**
     * Field-by-field comparison of the golden record vs the live Google account.
     * State vocabulary matches the AD/NextGen panels: match | differ | missing | info.
     *
     * @param array<string,mixed>  $person
     * @param array<string,string> $attrs  normalized Google attributes
     * @return array<int,array{field:string,label:string,golden:string,google:string,state:string}>
     */
    public static function compareToGolden(array $person, array $attrs): array
    {
        $rows = [];
        $rows[] = self::compareRow('primaryEmail', 'Primary email', (string) ($person['email'] ?? ''), $attrs['primaryemail'] ?? null, caseInsensitive: true);
        $rows[] = self::compareRow('givenName', 'First name', (string) ($person['first_name'] ?? ''), $attrs['givenname'] ?? null);
        $rows[] = self::compareRow('familyName', 'Last name', (string) ($person['last_name'] ?? ''), $attrs['familyname'] ?? null);

        // Suspended vs lifecycle status (active/pending expect NOT suspended).
        $status = (string) ($person['status'] ?? '');
        $expectActive = in_array($status, ['active', 'pending'], true);
        if (!array_key_exists('suspended', $attrs) || $attrs['suspended'] === '') {
            $rows[] = ['field' => 'suspended', 'label' => 'Account state', 'golden' => self::stateWord($expectActive), 'google' => '', 'state' => 'missing'];
        } else {
            $suspended = self::truthy($attrs['suspended']);
            $rows[] = [
                'field'  => 'suspended',
                'label'  => 'Account state',
                'golden' => self::stateWord($expectActive) . ' (status: ' . ($status ?: '—') . ')',
                'google' => $suspended ? 'Suspended' : 'Active',
                'state'  => ($suspended === !$expectActive) ? 'match' : 'differ',
            ];
        }

        // Context-only.
        foreach (['orgunitpath' => 'Org unit', 'externalid' => 'External id', 'fullname' => 'Display name'] as $key => $label) {
            if (($attrs[$key] ?? '') !== '') {
                $rows[] = ['field' => $key, 'label' => $label, 'golden' => '', 'google' => $attrs[$key], 'state' => 'info'];
            }
        }
        return $rows;
    }

    /** Count of differing/missing comparison rows (the panel's headline number). */
    public static function diffCount(array $comparison): int
    {
        return count(array_filter($comparison, static fn($r) => in_array($r['state'], ['differ', 'missing'], true)));
    }

    // ---- request-body builders (pure, unit-tested) --------------------------

    /**
     * Build the users.insert body. A random initial password is set with
     * changePasswordAtNextLogin so the account is never left password-less.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    public static function buildCreateBody(array $person, string $email, ?string $orgUnitPath): array
    {
        $body = [
            'primaryEmail' => $email,
            'name' => [
                'givenName'  => (string) ($person['first_name'] ?? ''),
                'familyName' => (string) ($person['last_name'] ?? ''),
            ],
            'password' => self::randomPassword(),
            'changePasswordAtNextLogin' => true,
            'suspended' => false,
            'orgUnitPath' => self::normalizeOu($orgUnitPath),
        ];
        $employeeId = trim((string) ($person['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $body['externalIds'] = [['value' => $employeeId, 'type' => 'organization']];
        }
        return $body;
    }

    /**
     * Build a users.patch body from the golden record — only the manageable
     * fields (name, OU, externalId). Returns [] when there is nothing to set.
     *
     * @param array<string,mixed> $person
     * @return array<string,mixed>
     */
    public static function buildUpdateBody(array $person, ?string $orgUnitPath): array
    {
        $body = [];
        $first = (string) ($person['first_name'] ?? '');
        $last = (string) ($person['last_name'] ?? '');
        if ($first !== '' || $last !== '') {
            $body['name'] = ['givenName' => $first, 'familyName' => $last];
        }
        if ($orgUnitPath !== null && trim($orgUnitPath) !== '') {
            $body['orgUnitPath'] = self::normalizeOu($orgUnitPath);
        }
        $employeeId = trim((string) ($person['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $body['externalIds'] = [['value' => $employeeId, 'type' => 'organization']];
        }
        return $body;
    }

    // ---- internals ----------------------------------------------------------

    /** @param array<int,array<string,mixed>> $sourceIds */
    private static function googleSourceKey(array $sourceIds): ?string
    {
        $fallback = null;
        foreach ($sourceIds as $row) {
            if (strtolower((string) ($row['system'] ?? '')) !== 'google') {
                continue;
            }
            $key = trim((string) ($row['source_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!empty($row['is_active'])) {
                return $key;
            }
            $fallback ??= $key;
        }
        return $fallback;
    }

    /**
     * @param array<string,mixed>  $person
     * @param array<string,string> $attrs
     * @return Envelope
     */
    private function foundEnvelope(array $person, array $attrs, string $by, string $identifier, bool $auto): array
    {
        return self::envelope(
            ok: true,
            configured: true,
            found: true,
            auto: $auto,
            by: $by,
            identifier: $identifier,
            attributes: $attrs,
            comparison: self::compareToGolden($person, $attrs),
            googleId: $attrs['id'] ?? null,
            primaryEmail: $attrs['primaryemail'] ?? null,
            suspended: array_key_exists('suspended', $attrs) && $attrs['suspended'] !== '' ? self::truthy($attrs['suspended']) : null,
        );
    }

    /**
     * Flatten a Google user resource into a case-insensitive scalar map for
     * comparison + crosswalk (id, primaryEmail, givenName, familyName, fullName,
     * suspended, orgUnitPath, externalId).
     *
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private static function normalizeUser(array $data): array
    {
        $out = [];
        $out['id'] = self::scalar($data['id'] ?? '');
        $out['primaryemail'] = self::scalar($data['primaryEmail'] ?? '');
        $name = is_array($data['name'] ?? null) ? $data['name'] : [];
        $out['givenname'] = self::scalar($name['givenName'] ?? '');
        $out['familyname'] = self::scalar($name['familyName'] ?? '');
        $out['fullname'] = self::scalar($name['fullName'] ?? trim($out['givenname'] . ' ' . $out['familyname']));
        $out['orgunitpath'] = self::scalar($data['orgUnitPath'] ?? '');
        if (array_key_exists('suspended', $data)) {
            $out['suspended'] = self::truthy(self::scalar($data['suspended'])) ? 'true' : 'false';
        }
        $ext = $data['externalIds'] ?? null;
        if (is_array($ext) && isset($ext[0]) && is_array($ext[0])) {
            $out['externalid'] = self::scalar($ext[0]['value'] ?? '');
        }
        return array_filter($out, static fn($v) => $v !== '');
    }

    /** @return array{field:string,label:string,golden:string,google:string,state:string} */
    private static function compareRow(string $field, string $label, string $golden, ?string $google, bool $caseInsensitive = false): array
    {
        $golden = trim($golden);
        $google = trim((string) ($google ?? ''));
        if ($golden === '' && $google === '') {
            $state = 'info';
        } elseif ($golden === '' || $google === '') {
            $state = 'missing';
        } else {
            $a = $caseInsensitive ? mb_strtolower($golden) : $golden;
            $b = $caseInsensitive ? mb_strtolower($google) : $google;
            $state = $a === $b ? 'match' : 'differ';
        }
        return ['field' => $field, 'label' => $label, 'golden' => $golden, 'google' => $google, 'state' => $state];
    }

    private static function stateWord(bool $active): string
    {
        return $active ? 'Active' : 'Suspended';
    }

    private static function truthy(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function scalar(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        return is_scalar($v) ? trim((string) $v) : '';
    }

    /** Normalize an OU path to Google's leading-slash form ('/', '/Faculty'). */
    private static function normalizeOu(?string $ou): string
    {
        $ou = trim((string) $ou);
        if ($ou === '' || $ou === '/') {
            return '/';
        }
        return '/' . ltrim($ou, '/');
    }

    /** A strong random initial password (Google requires ≥ 8 chars). */
    private static function randomPassword(): string
    {
        return 'Aa1!' . bin2hex(random_bytes(16));
    }

    /** Quote a Directory API query value. */
    private static function quote(string $v): string
    {
        return "'" . str_replace("'", '', $v) . "'";
    }

    /**
     * Perform an authenticated Directory API request and decode it.
     *
     * @return array{ok:bool, error:?string, data:array<string,mixed>, status:int}
     */
    private function request(string $method, string $url, ?string $body = null): array
    {
        $token = $this->authToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Google authentication failed: ' . ($this->authError ?? 'unknown') . '.', 'data' => [], 'status' => 0];
        }
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $resp = ($this->fetch)($method, $url, $headers, $body);
        $decoded = $this->decode($resp);
        $status = $resp['status'] ?? 0;
        if (!$decoded['ok'] && $status > 0 && $decoded['error'] !== null) {
            $decoded['error'] .= ' [' . $method . ' ' . self::urlPath($url) . ']';
        }
        $decoded['status'] = $status;
        return $decoded;
    }

    /**
     * Resolve the OAuth2 access token. A test-injected token wins; otherwise mint
     * one via the service-account JWT-bearer handshake and cache it per instance.
     */
    private function authToken(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if ($this->authAttempted) {
            return null;
        }
        $this->authAttempted = true;

        $assertion = $this->buildAssertion();
        if ($assertion === null) {
            return null; // authError already set
        }
        $form = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);
        $resp = ($this->fetch)('POST', $this->tokenUri, ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], $form);
        $decoded = $this->decode($resp);
        if (!$decoded['ok']) {
            $this->authError = 'token endpoint ' . ($decoded['error'] ?? 'error');
            return null;
        }
        $token = $decoded['data']['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->authError = 'no access_token in token response';
            return null;
        }
        return $this->accessToken = $token;
    }

    /** Build + sign the service-account JWT assertion (RS256), or null on failure. */
    private function buildAssertion(): ?string
    {
        if ($this->clientEmail === '' || $this->privateKey === '' || $this->adminSubject === '') {
            $this->authError = 'missing GOOGLE_SA_CLIENT_EMAIL / GOOGLE_SA_PRIVATE_KEY / GOOGLE_ADMIN_SUBJECT';
            return null;
        }
        // iat/exp: signed for 1 hour. gmdate is deterministic enough; a small
        // clock skew is tolerated by Google.
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $this->clientEmail,
            'sub'   => $this->adminSubject,
            'scope' => $this->scopes,
            'aud'   => $this->tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];
        $input = self::b64url((string) json_encode($header)) . '.' . self::b64url((string) json_encode($claims));
        $signature = ($this->signer)($input);
        if ($signature === null || $signature === '') {
            $this->authError = 'could not sign JWT (check GOOGLE_SA_PRIVATE_KEY)';
            return null;
        }
        return $input . '.' . self::b64url($signature);
    }

    /** Native RS256 signature over $input with the SA private key. Null on failure. */
    private function opensslSign(string $input): ?string
    {
        if (!function_exists('openssl_sign')) {
            return null;
        }
        $key = @openssl_pkey_get_private($this->privateKey);
        if ($key === false) {
            return null;
        }
        $signature = '';
        $ok = @openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);
        return $ok ? $signature : null;
    }

    /** URL-safe base64 without padding (JWT / JWS convention). */
    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Decode an HTTP response into JSON, classifying failures into a message.
     *
     * @param array{status:int,body:string}|null $resp
     * @return array{ok:bool, error:?string, data:array<string,mixed>, status:int}
     */
    private function decode(?array $resp): array
    {
        if ($resp === null) {
            return ['ok' => false, 'error' => 'Google is unreachable.', 'data' => [], 'status' => 0];
        }
        $status = $resp['status'] ?? 0;
        $data = json_decode((string) ($resp['body'] ?? ''), true);
        $data = is_array($data) ? $data : [];

        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => 'Google rejected the request (HTTP ' . $status . ') — check the service-account delegation, scopes, and GOOGLE_ADMIN_SUBJECT: ' . self::apiError($data), 'data' => $data, 'status' => $status];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Google returned HTTP ' . $status . ': ' . self::apiError($data), 'data' => $data, 'status' => $status];
        }
        return ['ok' => true, 'error' => null, 'data' => $data, 'status' => $status];
    }

    /** Pull a human message out of a Google API error body. */
    private static function apiError(array $data): string
    {
        $err = $data['error'] ?? null;
        if (is_array($err)) {
            if (isset($err['message']) && is_string($err['message'])) {
                return $err['message'];
            }
            if (isset($err['errors'][0]['message']) && is_string($err['errors'][0]['message'])) {
                return (string) $err['errors'][0]['message'];
            }
        }
        if (is_string($err) && $err !== '') {
            return $err . (isset($data['error_description']) ? ': ' . (string) $data['error_description'] : '');
        }
        return 'no error detail';
    }

    private static function urlPath(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false ? $url : substr($url, 0, $q);
    }

    /**
     * Real HTTP transport (native streams, TLS on). Returns status + body, or
     * null on a transport-level failure. Honors an internal CA bundle
     * (GOOGLE_CA_FILE); verification stays ON unless explicitly disabled.
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
        $verifyTls = Config::bool('GOOGLE_VERIFY_TLS', true);
        $caFile = (string) Config::get('GOOGLE_CA_FILE', '');

        $ctx = stream_context_create([
            'http' => [
                'method'         => $method,
                'timeout'        => $this->timeout,
                'ignore_errors'  => true,
                'follow_location' => 0,
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
        return ['status' => self::statusFromHeaders($http_response_header ?? []), 'body' => $raw];
    }

    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /**
     * Load the SA client_email + private_key from GOOGLE_SA_KEY_FILE (path to the
     * downloaded JSON key) or GOOGLE_SA_JSON (inline). Returns ['', ''] when
     * neither is set or parseable — explicit GOOGLE_SA_* env still wins in the ctor.
     *
     * @return array{0:string,1:string}
     */
    private static function loadServiceAccount(): array
    {
        $json = '';
        $file = trim((string) Config::get('GOOGLE_SA_KEY_FILE', ''));
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $json = (string) file_get_contents($file);
        }
        if ($json === '') {
            $json = trim((string) Config::get('GOOGLE_SA_JSON', ''));
        }
        if ($json === '') {
            return ['', ''];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return ['', ''];
        }
        return [(string) ($data['client_email'] ?? ''), (string) ($data['private_key'] ?? '')];
    }

    /**
     * @return Envelope
     * @param array<string,string> $attributes
     * @param array<int,array{field:string,label:string,golden:string,google:string,state:string}> $comparison
     */
    private static function envelope(
        bool $ok,
        bool $configured,
        bool $found = false,
        bool $auto = false,
        ?string $error = null,
        ?string $by = null,
        ?string $identifier = null,
        array $attributes = [],
        array $comparison = [],
        ?string $googleId = null,
        ?string $primaryEmail = null,
        ?bool $suspended = null,
    ): array {
        return [
            'ok' => $ok, 'error' => $error, 'configured' => $configured,
            'found' => $found, 'auto' => $auto, 'by' => $by, 'identifier' => $identifier,
            'attributes' => $attributes, 'comparison' => $comparison,
            'googleId' => $googleId, 'primaryEmail' => $primaryEmail, 'suspended' => $suspended,
        ];
    }

    /** @param array<string,string> $attrs @return WriteResult */
    private static function writeOk(string $action, array $attrs): array
    {
        return [
            'ok' => true, 'error' => null, 'action' => $action,
            'googleId' => $attrs['id'] ?? null,
            'primaryEmail' => $attrs['primaryemail'] ?? null,
            'suspended' => array_key_exists('suspended', $attrs) && $attrs['suspended'] !== '' ? self::truthy($attrs['suspended']) : null,
            'attributes' => $attrs,
        ];
    }

    /** @return WriteResult */
    private static function writeFail(string $action, ?string $error): array
    {
        return ['ok' => false, 'error' => $error, 'action' => $action, 'googleId' => null, 'primaryEmail' => null, 'suspended' => null, 'attributes' => []];
    }
}
