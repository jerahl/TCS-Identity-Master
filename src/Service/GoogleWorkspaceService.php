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
 * TRANSPORT BACKENDS (GOOGLE_BACKEND): the correlation tiers, comparison, and
 * write semantics above are fixed; only the low-level directory calls swap.
 *   - api (default): the built-in Admin SDK HTTP client described above.
 *   - gam: shell out to GAM (https://github.com/GAM-team/GAM) via GamClient —
 *     auth lives entirely in GAM's own config, so the app holds no Google key.
 *
 * @phpstan-type Envelope array{ok:bool, error:?string, configured:bool, found:bool, auto:bool, by:?string, identifier:?string, attributes:array<string,string>, comparison:array<int,array{field:string,label:string,golden:string,google:string,state:string}>, googleId:?string, primaryEmail:?string, suspended:?bool}
 * @phpstan-type WriteResult array{ok:bool, error:?string, action:string, googleId:?string, primaryEmail:?string, suspended:?bool, attributes:array<string,string>}
 * @phpstan-type HttpResponse array{status:int, body:string}
 */
final class GoogleWorkspaceService
{
    /** Directory API + OAuth2 endpoints (host is fixed; overridable for tests). */
    private const DEFAULT_API_BASE = 'https://admin.googleapis.com/admin/directory/v1';
    private const DEFAULT_LICENSING_BASE = 'https://licensing.googleapis.com/apps/licensing/v1';
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const DEFAULT_SCOPES = 'https://www.googleapis.com/auth/admin.directory.user';

    private bool $enabled;
    /** 'api' (built-in Admin SDK client) or 'gam' (shell out via GamClient). */
    private string $backend;
    private ?GamClient $gam;
    private string $apiBase;
    private string $tokenUri;
    private string $scopes;
    private string $clientEmail;
    private string $privateKey;
    private string $adminSubject;
    private string $customer;
    private string $domain;
    private int $timeout;
    /** Licensing: Education Plus (staff) assign/remove — off unless configured. */
    private bool $licenseEnabled;
    private string $licenseSku;
    private string $licenseProduct;
    private int $licenseSeats;
    private string $licensingBase;

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
        ?GamClient $gam = null,
        ?string $backend = null,
    ) {
        $this->enabled = $enabled ?? Config::bool('GOOGLE_DIRECT_ENABLED', false);

        // An explicitly injected GamClient forces the gam backend (tests);
        // otherwise GOOGLE_BACKEND picks the transport, defaulting to the API.
        $resolved = strtolower(trim($backend ?? (string) Config::get('GOOGLE_BACKEND', 'api')));
        $this->backend = $gam !== null || $resolved === 'gam' ? 'gam' : 'api';
        $this->gam = $gam ?? ($this->backend === 'gam' ? new GamClient() : null);

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

        // Licensing (Enterprise License Manager). SKU is the Education Plus staff
        // SKU; product is inferred by GAM but required for the HTTP API. Seats = the
        // subscription's seat cap (0 = uncapped: don't gate on availability).
        $this->licenseEnabled = Config::bool('GOOGLE_LICENSE_ENABLED', false);
        $this->licenseSku     = trim((string) Config::get('GOOGLE_LICENSE_SKU', ''));
        $this->licenseProduct = trim((string) Config::get('GOOGLE_LICENSE_PRODUCT', ''));
        $this->licenseSeats   = max(0, (int) Config::get('GOOGLE_LICENSE_SEATS', '0'));
        $this->licensingBase  = rtrim((string) Config::get('GOOGLE_LICENSING_API_BASE', self::DEFAULT_LICENSING_BASE), '/');
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
        if ($this->gam !== null) {
            return $this->gam->configured();
        }
        if ($this->accessToken !== null) {
            return true;
        }
        return $this->clientEmail !== '' && $this->privateKey !== '' && $this->adminSubject !== '';
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    /**
     * A step-by-step health check of the API backend's authorization, for the
     * bin/google_auth_check.php setup script. Walks the same path a real call
     * takes — feature flag → credentials → JWT signing → token exchange → a
     * delegated Directory API read — and stops at the first failure so the
     * script can point at exactly what to fix. Never throws.
     *
     * @return array{backend:string, enabled:bool, clientEmail:string, clientId:string,
     *   adminSubject:string, domain:string, scopes:string, keySource:string,
     *   steps:list<array{name:string, ok:bool, detail:string}>, ok:bool}
     */
    public function diagnose(): array
    {
        $meta = self::serviceAccountMeta();
        $out = [
            'backend'      => $this->backend,
            'enabled'      => $this->enabled,
            'clientEmail'  => $this->clientEmail,
            'clientId'     => $meta['client_id'],
            'adminSubject' => $this->adminSubject,
            'domain'       => $this->domain,
            'scopes'       => $this->scopes,
            'keySource'    => $meta['source'],
            'steps'        => [],
            'ok'           => false,
        ];
        $steps = [];
        $add = static function (string $name, bool $ok, string $detail) use (&$steps): bool {
            $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
            return $ok;
        };
        $finish = static function (array $out, array $steps): array {
            $out['steps'] = $steps;
            $out['ok'] = $steps !== [] && array_reduce($steps, static fn(bool $c, array $s): bool => $c && $s['ok'], true);
            return $out;
        };

        if (!$add('Feature flag', $this->enabled, $this->enabled ? 'GOOGLE_DIRECT_ENABLED is on.' : 'GOOGLE_DIRECT_ENABLED is not true — direct provisioning is off.')) {
            return $finish($out, $steps);
        }
        if ($this->backend !== 'api') {
            $add('Backend', false, "GOOGLE_BACKEND is '{$this->backend}', not 'api'. This check is for the API backend; the GAM backend authenticates through gam itself.");
            return $finish($out, $steps);
        }
        $add('Backend', true, 'GOOGLE_BACKEND=api (built-in Directory API client).');

        $haveCreds = $this->clientEmail !== '' && $this->privateKey !== '' && $this->adminSubject !== '';
        if (!$add('Credentials present', $haveCreds, $haveCreds
            ? "service account {$this->clientEmail}, impersonating {$this->adminSubject}."
            : 'missing one of GOOGLE_SA_CLIENT_EMAIL / GOOGLE_SA_PRIVATE_KEY / GOOGLE_ADMIN_SUBJECT (a GOOGLE_SA_KEY_FILE supplies the first two).')) {
            return $finish($out, $steps);
        }

        if (!$add('Sign JWT', $this->buildAssertion() !== null, $this->authError === null
            ? 'signed a test assertion with the SA private key.'
            : ('could not sign the assertion — ' . $this->authError))) {
            return $finish($out, $steps);
        }

        if (!$add('Token exchange', $this->authToken() !== null, $this->authError === null
            ? 'obtained an OAuth access token from Google.'
            : ('the token endpoint rejected the assertion — ' . $this->authError))) {
            return $finish($out, $steps);
        }

        // A delegated read of the impersonated admin's own account: the first call
        // that actually exercises domain-wide delegation, the scopes, and that the
        // admin subject is a real user Google will let the SA act as.
        $res = $this->request('GET', $this->apiBase . '/users/' . rawurlencode($this->adminSubject) . '?projection=basic');
        $add('Directory API (delegated)', $res['ok'], $res['ok']
            ? "read {$this->adminSubject} over the Directory API — domain-wide delegation, scopes, and admin subject all check out."
            : (string) $res['error']);

        return $finish($out, $steps);
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
            return self::envelope(ok: false, configured: false, error: $this->offMessage());
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

        // Tier 2 — primary email. The golden email/UPN sit in the district's
        // on-prem domain (e.g. @tusc.k12.al.us), but the Google account lives
        // under GOOGLE_DOMAIN (e.g. @tuscaloosacityschools.com) — so the local
        // part re-homed to GOOGLE_DOMAIN is tried first, then the raw values.
        foreach ($this->primaryEmailCandidates($person) as $value) {
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
     * Ordered, de-duplicated primaryEmail candidates to correlate a person by.
     *
     * A person's golden email/UPN are typically in the district's on-prem domain,
     * while their Google account is under GOOGLE_DOMAIN — so the strongest
     * candidates are the local part re-homed to GOOGLE_DOMAIN: the AD username
     * first (the Google account convention), then the email/UPN local parts. The
     * raw golden email and UPN follow, for records that already carry a
     * Google-domain (or otherwise directly matching) address. De-duplicated
     * case-insensitively; only well-formed addresses are kept.
     *
     * @param array<string,mixed> $person
     * @return list<string>
     */
    private function primaryEmailCandidates(array $person): array
    {
        $username = trim((string) ($person['username'] ?? ''));
        $email    = trim((string) ($person['email'] ?? ''));
        $upn      = trim((string) ($person['upn'] ?? ''));

        $localParts = [];
        if ($username !== '') {
            $localParts[] = $username;
        }
        foreach ([$email, $upn] as $addr) {
            $at = strpos($addr, '@');
            if ($at !== false && $at > 0) {
                $localParts[] = substr($addr, 0, $at);
            }
        }

        $candidates = [];
        if ($this->domain !== '') {
            foreach ($localParts as $lp) {
                $candidates[] = $lp . '@' . $this->domain;
            }
        }
        $candidates[] = $email;
        $candidates[] = $upn;

        $seen = [];
        $out = [];
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c === '' || !str_contains($c, '@')) {
                continue;
            }
            $key = mb_strtolower($c);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * The person's derived Google Workspace email — the account convention used
     * across the app (person page, comparison panel, notify page). It is
     * <username>@GOOGLE_DOMAIN; with no username it re-homes the golden email/UPN
     * local part to GOOGLE_DOMAIN. Returns '' when GOOGLE_DOMAIN isn't configured
     * (callers fall back to the golden email). $domain is injectable and defaults
     * to GOOGLE_DOMAIN so templates can call this statically.
     *
     * @param array<string,mixed> $person
     */
    public static function googleEmailFor(array $person, ?string $domain = null): string
    {
        $domain = trim($domain ?? (string) Config::get('GOOGLE_DOMAIN', ''));
        if ($domain === '') {
            return '';
        }
        $username = trim((string) ($person['username'] ?? ''));
        if ($username !== '') {
            return $username . '@' . $domain;
        }
        foreach (['email', 'upn'] as $f) {
            $addr = trim((string) ($person[$f] ?? ''));
            $at = strpos($addr, '@');
            if ($at !== false && $at > 0) {
                return substr($addr, 0, $at) . '@' . $domain;
            }
        }
        return '';
    }

    /**
     * GET a single user by id or primaryEmail. A 404 is a clean not-found (so the
     * caller can fall through to the next correlation tier), not an error.
     *
     * @return array{ok:bool, error:?string, found:bool, attributes:array<string,string>}
     */
    public function getUser(string $userKey): array
    {
        if ($this->gam !== null) {
            $res = $this->gam->getUser($userKey);
            return ['ok' => $res['ok'], 'error' => $res['error'], 'found' => $res['found'],
                'attributes' => $res['found'] ? self::normalizeUser($res['data']) : []];
        }
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
        if ($this->gam !== null) {
            $res = $this->gam->searchUsers($query);
            return ['ok' => $res['ok'], 'error' => $res['error'], 'found' => $res['found'],
                'attributes' => $res['found'] ? self::normalizeUser($res['data']) : []];
        }
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
        if ($this->gam !== null) {
            // GamClient ignores the body's password and uses GAM's `password
            // random` so the initial secret never appears on a command line.
            $res = $this->gam->createUser($body);
            if (!$res['ok']) {
                return self::writeFail('create', $res['error']);
            }
            return self::writeOk('create', self::normalizeUser($res['data']));
        }
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
        if ($this->gam !== null) {
            $res = $this->gam->updateUser($userKey, $body);
            if (!$res['ok']) {
                return self::writeFail('edit', $res['error']);
            }
            return self::writeOk('edit', self::normalizeUser($res['data']));
        }
        $res = $this->request('PATCH', $this->apiBase . '/users/' . rawurlencode($userKey), (string) json_encode($body));
        if (!$res['ok']) {
            return self::writeFail('edit', $res['error']);
        }
        return self::writeOk('edit', self::normalizeUser($res['data']));
    }

    /**
     * Move a Google account to a different org unit (OU-only patch — leaves name,
     * suspension, etc. untouched). Used to relocate suspended users to the disabled
     * OU and to heal active users whose OU has drifted from their building's.
     *
     * @return WriteResult
     */
    public function moveUser(string $userKey, string $orgUnitPath): array
    {
        if (!$this->configured()) {
            return self::writeFail('edit', 'Direct Google provisioning is off.');
        }
        $body = ['orgUnitPath' => self::normalizeOu($orgUnitPath)];
        if ($this->gam !== null) {
            $res = $this->gam->updateUser($userKey, $body);
            return $res['ok'] ? self::writeOk('edit', self::normalizeUser($res['data'])) : self::writeFail('edit', $res['error']);
        }
        $res = $this->request('PATCH', $this->apiBase . '/users/' . rawurlencode($userKey), (string) json_encode($body));
        return $res['ok'] ? self::writeOk('edit', self::normalizeUser($res['data'])) : self::writeFail('edit', $res['error']);
    }

    // ---- licensing (Education Plus staff) --------------------------------------

    /** True when license management is switched on AND a SKU is configured. */
    public function licenseEnabled(): bool
    {
        return $this->licenseEnabled && $this->licenseSku !== '';
    }

    /** Configured seat cap (0 = uncapped: no availability gate). */
    public function licenseSeats(): int
    {
        return $this->licenseSeats;
    }

    /**
     * Assign the configured license SKU to a user. Idempotent. Callers should
     * check seat availability (licenseUsage) first when a cap is set.
     *
     * @return array{ok:bool,error:?string}
     */
    public function assignLicense(string $userKey): array
    {
        if (!$this->licenseEnabled()) {
            return ['ok' => false, 'error' => 'License management is off (GOOGLE_LICENSE_ENABLED + GOOGLE_LICENSE_SKU).'];
        }
        $userKey = trim($userKey);
        if ($userKey === '') {
            return ['ok' => false, 'error' => 'No user to license.'];
        }
        if ($this->gam !== null) {
            return $this->gam->addLicense($userKey, $this->licenseSku, $this->licenseProduct);
        }
        if ($this->licenseProduct === '') {
            return ['ok' => false, 'error' => 'GOOGLE_LICENSE_PRODUCT is required for the API backend.'];
        }
        $url = $this->licensingBase . '/product/' . rawurlencode($this->licenseProduct)
             . '/sku/' . rawurlencode($this->licenseSku) . '/user';
        $res = $this->request('POST', $url, (string) json_encode(['userId' => $userKey]));
        // 409 = already assigned → idempotent success.
        if ($res['ok'] || ($res['status'] ?? 0) === 409) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => $res['error']];
    }

    /**
     * Remove the configured license SKU from a user. Idempotent (a 404 = not
     * assigned = success).
     *
     * @return array{ok:bool,error:?string}
     */
    public function removeLicense(string $userKey): array
    {
        if (!$this->licenseEnabled()) {
            return ['ok' => false, 'error' => 'License management is off (GOOGLE_LICENSE_ENABLED + GOOGLE_LICENSE_SKU).'];
        }
        $userKey = trim($userKey);
        if ($userKey === '') {
            return ['ok' => false, 'error' => 'No user to unlicense.'];
        }
        if ($this->gam !== null) {
            return $this->gam->deleteLicense($userKey, $this->licenseSku, $this->licenseProduct);
        }
        if ($this->licenseProduct === '') {
            return ['ok' => false, 'error' => 'GOOGLE_LICENSE_PRODUCT is required for the API backend.'];
        }
        $url = $this->licensingBase . '/product/' . rawurlencode($this->licenseProduct)
             . '/sku/' . rawurlencode($this->licenseSku) . '/user/' . rawurlencode($userKey);
        $res = $this->request('DELETE', $url);
        if ($res['ok'] || ($res['status'] ?? 0) === 404) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => $res['error']];
    }

    /**
     * Current usage of the configured SKU: the set of assigned users (lowercased
     * email/id) and the used count, so the sync can both gate on seat availability
     * and tell per-user whether a license is held — from ONE lookup per run.
     * Returns null members when it can't be determined (degrade to "unknown").
     *
     * @return array{ok:bool, users:array<string,true>|null, used:?int, seats:int, available:?int}
     */
    public function licenseUsage(): array
    {
        $seats = $this->licenseSeats;
        if (!$this->licenseEnabled()) {
            return ['ok' => false, 'users' => null, 'used' => null, 'seats' => $seats, 'available' => null];
        }
        $users = $this->gam !== null
            ? $this->gam->listLicenseUsers($this->licenseSku, $this->licenseProduct)
            : $this->httpLicenseUsers();
        if ($users === null) {
            return ['ok' => false, 'users' => null, 'used' => null, 'seats' => $seats, 'available' => null];
        }
        $used = count($users);
        $available = $seats > 0 ? max(0, $seats - $used) : null; // null = uncapped
        return ['ok' => true, 'users' => $users, 'used' => $used, 'seats' => $seats, 'available' => $available];
    }

    /**
     * HTTP-backend assignment list for the configured SKU (paged, bounded). Null on
     * any error so the caller degrades to "unknown" rather than a false empty.
     *
     * @return array<string,true>|null
     */
    private function httpLicenseUsers(): ?array
    {
        if ($this->licenseProduct === '') {
            return null;
        }
        $set = [];
        $pageToken = '';
        for ($page = 0; $page < 50; $page++) { // bound: 50 pages × 100 = 5000
            $url = $this->licensingBase . '/product/' . rawurlencode($this->licenseProduct)
                 . '/sku/' . rawurlencode($this->licenseSku) . '/users'
                 . '?customerId=' . rawurlencode($this->customer) . '&maxResults=100'
                 . ($pageToken !== '' ? '&pageToken=' . rawurlencode($pageToken) : '');
            $res = $this->request('GET', $url);
            if (!$res['ok']) {
                return null;
            }
            foreach ((array) ($res['data']['items'] ?? []) as $item) {
                $id = trim((string) ($item['userId'] ?? ''));
                if ($id !== '') {
                    $set[strtolower($id)] = true;
                }
            }
            $pageToken = trim((string) ($res['data']['nextPageToken'] ?? ''));
            if ($pageToken === '') {
                break;
            }
        }
        return $set;
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

    /**
     * Add a secondary email alias to a Google account (keeps delivering the old
     * address after a rename). @return array{ok:bool,error:?string}
     */
    public function addAlias(string $userKey, string $alias): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Direct Google provisioning is off.'];
        }
        if ($this->gam !== null) {
            return $this->gam->addAlias($userKey, $alias);
        }
        $res = $this->request('POST', $this->apiBase . '/users/' . rawurlencode($userKey) . '/aliases', (string) json_encode(['alias' => $alias]));
        return ['ok' => $res['ok'], 'error' => $res['error']];
    }

    /** Remove a secondary email alias. @return array{ok:bool,error:?string} */
    public function removeAlias(string $userKey, string $alias): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'Direct Google provisioning is off.'];
        }
        if ($this->gam !== null) {
            return $this->gam->removeAlias($alias);
        }
        $res = $this->request('DELETE', $this->apiBase . '/users/' . rawurlencode($userKey) . '/aliases/' . rawurlencode($alias));
        return ['ok' => $res['ok'], 'error' => $res['error']];
    }

    private function setSuspended(string $userKey, bool $suspended, string $action): array
    {
        if (!$this->configured()) {
            return self::writeFail($action, 'Direct Google provisioning is off.');
        }
        if ($this->gam !== null) {
            $res = $this->gam->updateUser($userKey, ['suspended' => $suspended]);
            if (!$res['ok']) {
                return self::writeFail($action, $res['error']);
            }
            return self::writeOk($action, self::normalizeUser($res['data']));
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
        // Compare the golden Google email (<username>@GOOGLE_DOMAIN) against the
        // account's primaryEmail. Fall back to the golden email only when no
        // Google email could be derived (GOOGLE_DOMAIN unset).
        $goldenGoogleEmail = trim((string) ($person['google_email'] ?? ''));
        if ($goldenGoogleEmail === '') {
            $goldenGoogleEmail = trim((string) ($person['email'] ?? ''));
        }
        $rows[] = self::compareRow('primaryEmail', 'Google email', $goldenGoogleEmail, $attrs['primaryemail'] ?? null, caseInsensitive: true);
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

    /** What to configure, phrased for the active backend. */
    private function offMessage(): string
    {
        if ($this->backend === 'gam') {
            return 'Direct Google provisioning is off (set GOOGLE_DIRECT_ENABLED=true and, for the GAM backend, GAM_PATH to the gam binary).';
        }
        return 'Direct Google provisioning is off (set GOOGLE_DIRECT_ENABLED=true plus the GOOGLE_SA_* service-account credentials and GOOGLE_ADMIN_SUBJECT).';
    }

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
        // Carry the derived Google email so the comparison compares like-for-like
        // (golden Google email vs the account's primaryEmail), not the on-prem email.
        $person['google_email'] = self::googleEmailFor($person, $this->domain);
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
    public static function normalizeOu(?string $ou): string
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
     * Where the SA key came from, and its client_id — the numeric "OAuth client
     * ID" an admin pastes into Admin console → API controls → Domain-wide
     * delegation. Used by the setup script to print exact instructions.
     *
     * @return array{client_id:string, source:string}
     */
    private static function serviceAccountMeta(): array
    {
        $file = trim((string) Config::get('GOOGLE_SA_KEY_FILE', ''));
        $json = '';
        $source = 'GOOGLE_SA_CLIENT_EMAIL / GOOGLE_SA_PRIVATE_KEY (explicit env)';
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $json = (string) file_get_contents($file);
            $source = $file;
        } elseif (trim((string) Config::get('GOOGLE_SA_JSON', '')) !== '') {
            $json = trim((string) Config::get('GOOGLE_SA_JSON', ''));
            $source = 'GOOGLE_SA_JSON (inline)';
        }
        $clientId = '';
        if ($json !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $clientId = (string) ($data['client_id'] ?? '');
            }
        }
        return ['client_id' => $clientId, 'source' => $source];
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
