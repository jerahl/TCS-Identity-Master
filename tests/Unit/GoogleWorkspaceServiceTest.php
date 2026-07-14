<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GamClient;
use App\Service\GoogleWorkspaceService;
use PHPUnit\Framework\TestCase;

/**
 * The Google Workspace direct-provisioning client: it must (a) stay off until
 * enabled + credentialed, (b) correlate a person to their live Google account
 * in OneSync-style tiers (crosswalk id → primary email → employee id → name,
 * name never auto-links), (c) shape correct Directory API create/patch requests,
 * and (d) degrade to a clear ok=false envelope — never throw — when Google is
 * unreachable or rejects the call. The HTTP call is injected so no live Google
 * (and no real JWT signing) is needed: a static access token skips the handshake.
 */
final class GoogleWorkspaceServiceTest extends TestCase
{
    /** Build an enabled, credentialed service whose injected fetch returns a canned response + captures the request. */
    private function service(?array $response, ?array &$captured = null): GoogleWorkspaceService
    {
        $fetch = function (string $method, string $url, array $headers, ?string $body) use ($response, &$captured): ?array {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return $response;
        };
        // accessToken set → no JWT handshake; requests carry Authorization: Bearer.
        return new GoogleWorkspaceService(
            enabled: true, clientEmail: 'svc@x.iam', privateKey: 'k', adminSubject: 'admin@x.org',
            customer: 'my_customer', domain: 'x.org', timeout: 5, fetch: $fetch, signer: null,
            accessToken: 'test-token', apiBase: 'https://admin.example/admin/directory/v1',
            tokenUri: 'https://oauth.example/token', scopes: null,
        );
    }

    private function userResponse(array $overrides = []): array
    {
        // $overrides on the LEFT so the union operator lets them win over defaults.
        $user = $overrides + [
            'id' => '1234', 'primaryEmail' => 'jsmith@x.org',
            'name' => ['givenName' => 'John', 'familyName' => 'Smith', 'fullName' => 'John Smith'],
            'suspended' => false, 'orgUnitPath' => '/Faculty',
            'externalIds' => [['value' => 'E1', 'type' => 'organization']],
        ];
        return ['status' => 200, 'body' => (string) json_encode($user)];
    }

    public function testDisabledWhenFlagOff(): void
    {
        $svc = new GoogleWorkspaceService(enabled: false, fetch: fn() => null);
        self::assertFalse($svc->configured());

        $res = $svc->correlate(['email' => 'jsmith@x.org'], []);
        self::assertFalse($res['ok']);
        self::assertFalse($res['configured']);
        self::assertStringContainsString('GOOGLE_DIRECT_ENABLED', (string) $res['error']);
    }

    public function testCorrelatesByCrosswalkId(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);
        $person = ['email' => 'jsmith@x.org', 'first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active'];
        $sourceIds = [['system' => 'google', 'source_key' => '1234', 'is_active' => 1]];

        $res = $svc->correlate($person, $sourceIds);

        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertTrue($res['auto']);
        self::assertSame('id', $res['by']);
        self::assertSame('1234', $res['googleId']);
        self::assertFalse($res['suspended']);
        self::assertSame('GET', $captured['method']);
        self::assertStringContainsString('/users/1234', $captured['url']);
        self::assertStringContainsString('projection=full', $captured['url']);
        self::assertSame('Bearer test-token', $captured['headers']['Authorization']);
        self::assertSame(0, GoogleWorkspaceService::diffCount($res['comparison']));
    }

    public function testCorrelatesByPrimaryEmail(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);
        $res = $svc->correlate(['email' => 'jsmith@x.org', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('email', $res['by']);
        self::assertTrue($res['auto']);
        self::assertStringContainsString('/users/jsmith%40x.org', $captured['url']);
    }

    public function testCorrelatesByExternalIdViaSearch(): void
    {
        $captured = null;
        $svc = $this->service(['status' => 200, 'body' => (string) json_encode(['users' => [json_decode($this->userResponse()['body'], true)]])], $captured);
        // No email/upn → falls through to the externalId search tier.
        $res = $svc->correlate(['employee_id' => 'E1', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('externalId', $res['by']);
        self::assertTrue($res['auto']);
        self::assertStringContainsString('/users?', $captured['url']);
        self::assertStringContainsString('query=', $captured['url']);
        self::assertStringContainsString('externalId', rawurldecode($captured['url']));
    }

    public function testNameMatchIsNeverAutoLinked(): void
    {
        $svc = $this->service(['status' => 200, 'body' => (string) json_encode(['users' => [json_decode($this->userResponse()['body'], true)]])]);
        // Only a name to go on → review suggestion, auto=false.
        $res = $svc->correlate(['first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('name', $res['by']);
        self::assertFalse($res['auto']);
    }

    public function testNotFoundWhenGoogle404s(): void
    {
        $svc = $this->service(['status' => 404, 'body' => (string) json_encode(['error' => ['message' => 'Not Found']])]);
        $res = $svc->correlate(['email' => 'nobody@x.org', 'status' => 'active'], []);

        self::assertTrue($res['ok']);
        self::assertFalse($res['found']);
    }

    public function testForeignDomainGoldenEmailIsNotQueriedAndFallsThroughToName(): void
    {
        // GOOGLE_DOMAIN is x.org, but the golden email is in the on-prem domain
        // (onprem.local). Google 403s a userKey in a domain it doesn't own, which
        // would abort correlation — so the on-prem address must never be queried,
        // and correlation must fall through to the name tier instead.
        $urls = [];
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$urls): ?array {
            $urls[] = $url;
            if (str_contains($url, '/users/') && str_contains($url, 'jsmith%40x.org')) {
                return ['status' => 404, 'body' => (string) json_encode(['error' => ['message' => 'Not Found']])]; // re-homed miss
            }
            if (str_contains($url, '/users?') && str_contains(rawurldecode($url), 'givenName')) {
                return ['status' => 200, 'body' => (string) json_encode(['users' => [json_decode($this->userResponse()['body'], true)]])];
            }
            return ['status' => 200, 'body' => (string) json_encode(['users' => []])]; // any other query → not found
        };
        $svc = new GoogleWorkspaceService(
            enabled: true, clientEmail: 'svc@x.iam', privateKey: 'k', adminSubject: 'admin@x.org',
            customer: 'my_customer', domain: 'x.org', timeout: 5, fetch: $fetch, signer: null,
            accessToken: 'test-token', apiBase: 'https://admin.example/admin/directory/v1',
            tokenUri: 'https://oauth.example/token', scopes: null,
        );

        $res = $svc->correlate([
            'email' => 'jsmith@onprem.local', 'upn' => 'jsmith@onprem.local',
            'first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active',
        ], []);

        self::assertTrue($res['ok']);         // no 403 abort
        self::assertTrue($res['found']);
        self::assertSame('name', $res['by']); // fell through past the email tier
        self::assertFalse($res['auto']);
        foreach ($urls as $u) {
            self::assertStringNotContainsString('onprem.local', rawurldecode($u), 'foreign domain must never be queried');
        }
        self::assertNotEmpty(array_filter($urls, static fn($u) => str_contains($u, 'jsmith%40x.org')), 're-homed candidate should be tried');
    }

    public function testRawGoldenEmailStillUsedWhenNoGoogleDomain(): void
    {
        // With GOOGLE_DOMAIN unset there is nothing to re-home to, so the raw golden
        // email is the only email candidate (backward-compatible behavior).
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = $url;
            return $this->userResponse();
        };
        $svc = new GoogleWorkspaceService(
            enabled: true, clientEmail: 'svc@x.iam', privateKey: 'k', adminSubject: 'admin@x.org',
            customer: 'my_customer', domain: '', timeout: 5, fetch: $fetch, signer: null,
            accessToken: 'test-token', apiBase: 'https://admin.example/admin/directory/v1',
            tokenUri: 'https://oauth.example/token', scopes: null,
        );

        $res = $svc->correlate(['email' => 'jsmith@onprem.local', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('email', $res['by']);
        self::assertStringContainsString('jsmith%40onprem.local', (string) $captured);
    }

    public function testCreateUserBuildsCorrectBody(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);
        $person = ['email' => 'jsmith@x.org', 'first_name' => 'John', 'last_name' => 'Smith', 'employee_id' => 'E1'];

        $res = $svc->createUser($person, '/Faculty');

        self::assertTrue($res['ok']);
        self::assertSame('1234', $res['googleId']);
        self::assertSame('POST', $captured['method']);
        self::assertStringEndsWith('/users', $captured['url']);
        $body = json_decode((string) $captured['body'], true);
        self::assertSame('jsmith@x.org', $body['primaryEmail']);
        self::assertSame('John', $body['name']['givenName']);
        self::assertSame('Smith', $body['name']['familyName']);
        self::assertFalse($body['suspended']);
        self::assertTrue($body['changePasswordAtNextLogin']);
        self::assertNotSame('', (string) $body['password']);
        self::assertSame('/Faculty', $body['orgUnitPath']);
        self::assertSame('E1', $body['externalIds'][0]['value']);
    }

    public function testCreateUserAddressesNewAccountUnderGoogleDomain(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);
        // Golden email is in the on-prem domain; the new Google account must be
        // created under GOOGLE_DOMAIN (x.org here), local part preserved.
        $res = $svc->createUser(['email' => 'jsmith@onprem.local', 'first_name' => 'John', 'last_name' => 'Smith'], '/Faculty');

        self::assertTrue($res['ok']);
        $body = json_decode((string) $captured['body'], true);
        self::assertSame('jsmith@x.org', $body['primaryEmail']);
    }

    public function testCreateUserPrefersUsernameLocalPartUnderGoogleDomain(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);
        // Username is the account convention — it wins over the email local part.
        $res = $svc->createUser(['username' => 'jsmith', 'email' => 'john.smith@onprem.local', 'first_name' => 'John', 'last_name' => 'Smith'], '/Faculty');

        self::assertTrue($res['ok']);
        $body = json_decode((string) $captured['body'], true);
        self::assertSame('jsmith@x.org', $body['primaryEmail']);
    }

    public function testCreateUserStillRequiresAGoldenEmail(): void
    {
        $svc = $this->service($this->userResponse());
        $res = $svc->createUser(['username' => 'jsmith', 'first_name' => 'John', 'last_name' => 'Smith'], '/Faculty');

        self::assertFalse($res['ok']);
        self::assertStringContainsString('No golden email', (string) $res['error']);
    }

    public function testAssignLicensePostsToLicensingApi(): void
    {
        putenv('GOOGLE_LICENSE_ENABLED=true');
        putenv('GOOGLE_LICENSE_SKU=1010310008');
        putenv('GOOGLE_LICENSE_PRODUCT=101031');
        try {
            $captured = null;
            $svc = $this->service(['status' => 200, 'body' => '{}'], $captured);
            $res = $svc->assignLicense('jsmith@x.org');

            self::assertTrue($res['ok']);
            self::assertSame('POST', $captured['method']);
            self::assertStringContainsString('/product/101031/sku/1010310008/user', $captured['url']);
            self::assertSame(['userId' => 'jsmith@x.org'], json_decode((string) $captured['body'], true));
        } finally {
            putenv('GOOGLE_LICENSE_ENABLED');
            putenv('GOOGLE_LICENSE_SKU');
            putenv('GOOGLE_LICENSE_PRODUCT');
        }
    }

    public function testRemoveLicenseTreats404AsSuccess(): void
    {
        putenv('GOOGLE_LICENSE_ENABLED=true');
        putenv('GOOGLE_LICENSE_SKU=1010310008');
        putenv('GOOGLE_LICENSE_PRODUCT=101031');
        try {
            $captured = null;
            // 404 = not assigned → idempotent success.
            $svc = $this->service(['status' => 404, 'body' => (string) json_encode(['error' => ['message' => 'Not Found']])], $captured);
            $res = $svc->removeLicense('jsmith@x.org');

            self::assertTrue($res['ok']);
            self::assertSame('DELETE', $captured['method']);
            self::assertStringContainsString('/product/101031/sku/1010310008/user/jsmith%40x.org', $captured['url']);
        } finally {
            putenv('GOOGLE_LICENSE_ENABLED');
            putenv('GOOGLE_LICENSE_SKU');
            putenv('GOOGLE_LICENSE_PRODUCT');
        }
    }

    public function testLicenseDisabledWhenNotConfigured(): void
    {
        // Flag on but no SKU → licenseEnabled() false, writes refuse without HTTP.
        putenv('GOOGLE_LICENSE_ENABLED=true');
        try {
            $captured = null;
            $svc = $this->service(['status' => 200, 'body' => '{}'], $captured);
            self::assertFalse($svc->licenseEnabled());
            $res = $svc->assignLicense('jsmith@x.org');
            self::assertFalse($res['ok']);
            self::assertNull($captured); // never called Google
        } finally {
            putenv('GOOGLE_LICENSE_ENABLED');
        }
    }

    public function testMoveUserPatchesOrgUnitPathOnly(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);

        $res = $svc->moveUser('1234', 'tcs/faculty/disabled');

        self::assertTrue($res['ok']);
        self::assertSame('PATCH', $captured['method']);
        self::assertStringContainsString('/users/1234', $captured['url']);
        $body = json_decode((string) $captured['body'], true);
        self::assertSame('/tcs/faculty/disabled', $body['orgUnitPath']); // normalized leading slash
        self::assertArrayNotHasKey('name', $body);       // OU-only — no name/suspend/externalId
        self::assertArrayNotHasKey('suspended', $body);
        self::assertArrayNotHasKey('externalIds', $body);
    }

    public function testCreateUserRequiresGoldenEmail(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(), $captured);

        $res = $svc->createUser(['first_name' => 'John', 'last_name' => 'Smith'], null);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('golden email', (string) $res['error']);
        self::assertNull($captured); // never called Google
    }

    public function testSuspendPatchesSuspendedTrue(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(['suspended' => true]), $captured);

        $res = $svc->suspendUser('1234');

        self::assertTrue($res['ok']);
        self::assertTrue($res['suspended']);
        self::assertSame('PATCH', $captured['method']);
        self::assertStringContainsString('/users/1234', $captured['url']);
        self::assertSame(['suspended' => true], json_decode((string) $captured['body'], true));
    }

    public function testRestorePatchesSuspendedFalse(): void
    {
        $captured = null;
        $svc = $this->service($this->userResponse(['suspended' => false]), $captured);

        $res = $svc->restoreUser('1234');

        self::assertTrue($res['ok']);
        self::assertSame(['suspended' => false], json_decode((string) $captured['body'], true));
    }

    public function testTransportFailureNeverThrows(): void
    {
        $svc = $this->service(null); // null = transport-level failure
        $res = $svc->correlate(['email' => 'jsmith@x.org', 'status' => 'active'], []);

        self::assertFalse($res['ok']);
        self::assertTrue($res['configured']);
        self::assertStringContainsString('unreachable', (string) $res['error']);
    }

    public function testRejectedCredentialsSurfaceMessage(): void
    {
        $svc = $this->service(['status' => 403, 'body' => (string) json_encode(['error' => ['message' => 'Not Authorized to access this resource/api']])]);
        $res = $svc->correlate(['email' => 'jsmith@x.org', 'status' => 'active'], []);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('delegation', (string) $res['error']);
    }

    public function testDiffCountFlagsMismatch(): void
    {
        $svc = $this->service($this->userResponse(['primaryEmail' => 'other@x.org']));
        $res = $svc->correlate(['email' => 'jsmith@x.org', 'first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active'],
            [['system' => 'google', 'source_key' => '1234', 'is_active' => 1]]);

        $byField = [];
        foreach ($res['comparison'] as $row) {
            $byField[$row['field']] = $row['state'];
        }
        self::assertSame('differ', $byField['primaryEmail']);
        self::assertSame('match', $byField['givenName']);
    }

    public function testGoogleEmailForDerivesFromUsernameThenLocalPart(): void
    {
        self::assertSame('jsmith@tuscaloosacityschools.com', GoogleWorkspaceService::googleEmailFor(
            ['username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us'], 'tuscaloosacityschools.com'));
        // No username → re-home the golden email local part.
        self::assertSame('jdoe@tuscaloosacityschools.com', GoogleWorkspaceService::googleEmailFor(
            ['email' => 'jdoe@tusc.k12.al.us'], 'tuscaloosacityschools.com'));
        // No GOOGLE_DOMAIN → '' (callers fall back to the golden email).
        self::assertSame('', GoogleWorkspaceService::googleEmailFor(['username' => 'jsmith'], ''));
    }

    public function testDiagnosePassesThroughTheDelegatedApiCall(): void
    {
        // Token endpoint mints a token; the delegated Directory read of the admin
        // user succeeds — so every step passes.
        $fetch = static function (string $m, string $url, array $h, ?string $b): ?array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'tok', 'expires_in' => 3600])];
            }
            return ['status' => 200, 'body' => (string) json_encode(['id' => '1', 'primaryEmail' => 'admin@x.org'])];
        };
        $svc = new GoogleWorkspaceService(
            enabled: true, clientEmail: 'svc@x.iam', privateKey: 'k', adminSubject: 'admin@x.org', domain: 'x.org',
            fetch: $fetch, signer: static fn(string $i): string => 'sig',
            apiBase: 'https://admin.example/admin/directory/v1', tokenUri: 'https://oauth.example/token', backend: 'api',
        );

        $d = $svc->diagnose();

        self::assertTrue($d['ok']);
        self::assertSame('api', $d['backend']);
        $byName = array_column($d['steps'], 'ok', 'name');
        self::assertTrue($byName['Token exchange']);
        self::assertTrue($byName['Directory API (delegated)']);
    }

    public function testDiagnoseFlagsDelegationFailureFromA403(): void
    {
        $fetch = static function (string $m, string $url, array $h, ?string $b): ?array {
            if (str_contains($url, '/token')) {
                return ['status' => 200, 'body' => (string) json_encode(['access_token' => 'tok'])];
            }
            return ['status' => 403, 'body' => (string) json_encode(['error' => ['message' => 'Not Authorized to access this resource/api']])];
        };
        $svc = new GoogleWorkspaceService(
            enabled: true, clientEmail: 'svc@x.iam', privateKey: 'k', adminSubject: 'admin@x.org', domain: 'x.org',
            fetch: $fetch, signer: static fn(string $i): string => 'sig',
            apiBase: 'https://admin.example/admin/directory/v1', tokenUri: 'https://oauth.example/token', backend: 'api',
        );

        $d = $svc->diagnose();

        self::assertFalse($d['ok']);
        $api = null;
        foreach ($d['steps'] as $s) {
            if ($s['name'] === 'Directory API (delegated)') {
                $api = $s;
            }
        }
        self::assertNotNull($api);
        self::assertFalse($api['ok']);
        self::assertStringContainsString('delegation', (string) $api['detail']);
    }

    public function testDiagnoseStopsAtMissingCredentialsWithoutAnyHttp(): void
    {
        $svc = new GoogleWorkspaceService(
            enabled: true, clientEmail: 'svc@x.iam', privateKey: 'k', adminSubject: '', domain: 'x.org',
            fetch: static fn() => self::fail('must not hit the network before credentials are present'),
            signer: static fn(string $i): string => 'sig', backend: 'api',
        );

        $d = $svc->diagnose();

        self::assertFalse($d['ok']);
        $byName = array_column($d['steps'], 'ok', 'name');
        self::assertFalse($byName['Credentials present']);
    }

    public function testDiagnoseReportsGamBackendAsNotApplicable(): void
    {
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: static fn() => null);
        $svc = new GoogleWorkspaceService(enabled: true, fetch: static fn() => self::fail('gam backend uses no HTTP here'), gam: $gam);

        $d = $svc->diagnose();

        self::assertFalse($d['ok']);
        self::assertSame('gam', $d['backend']);
        $byName = array_column($d['steps'], 'ok', 'name');
        self::assertFalse($byName['Backend']);
    }

    public function testSuspendedVsActiveStatusDiffers(): void
    {
        $svc = $this->service($this->userResponse(['suspended' => true]));
        $res = $svc->correlate(['email' => 'jsmith@x.org', 'status' => 'active'],
            [['system' => 'google', 'source_key' => '1234', 'is_active' => 1]]);

        $state = null;
        foreach ($res['comparison'] as $row) {
            if ($row['field'] === 'suspended') {
                $state = $row['state'];
            }
        }
        self::assertSame('differ', $state); // golden active but Google suspended
    }

    public function testBuildUpdateBodyOnlySetsProvidedFields(): void
    {
        $body = GoogleWorkspaceService::buildUpdateBody(['first_name' => 'Jane', 'last_name' => 'Doe'], null);
        self::assertSame(['givenName' => 'Jane', 'familyName' => 'Doe'], $body['name']);
        self::assertArrayNotHasKey('orgUnitPath', $body);

        $withOu = GoogleWorkspaceService::buildUpdateBody(['first_name' => 'Jane', 'last_name' => 'Doe', 'employee_id' => 'E9'], 'Staff');
        self::assertSame('/Staff', $withOu['orgUnitPath']); // OU normalized to leading slash
        self::assertSame('E9', $withOu['externalIds'][0]['value']);
    }
}
