<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdaxesService;
use PHPUnit\Framework\TestCase;

/**
 * The Adaxes verification client: it must (a) stay silent/disabled until
 * configured, (b) look up the live AD account by objectGUID (preferred) or
 * sAMAccountName, (c) compare it field-by-field to the golden record, and
 * (d) degrade to a clear ok=false envelope — never throw — when AD is
 * unreachable or rejects the credentials. The HTTP call is injected so no live
 * Adaxes server is needed.
 */
final class AdaxesServiceTest extends TestCase
{
    /** Build a configured service whose injected fetch returns a canned response. */
    private function service(?array $response, ?array &$captured = null): AdaxesService
    {
        $fetch = function (string $method, string $url, array $headers, ?string $body) use ($response, &$captured): ?array {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return $response;
        };
        // Static token → no handshake; data requests carry Adm-Authorization.
        return new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, null, 'test-token');
    }

    /** Adaxes "get object" response with a list-form properties payload. */
    private function objectResponse(array $props): array
    {
        $list = [];
        foreach ($props as $name => $value) {
            $list[] = ['name' => $name, 'value' => $value];
        }
        return ['status' => 200, 'body' => json_encode(['properties' => $list])];
    }

    /** The Windows FILETIME (100-ns ticks since 1601) for a Y-m-d at 00:00 UTC. */
    private static function filetime(string $ymd): string
    {
        return (string) (((int) strtotime($ymd . ' 00:00:00 UTC') + 11644473600) * 10000000);
    }

    /** The account-expiration comparison row from a verify() result, or null. */
    private static function expiryRowOf(array $res): ?array
    {
        foreach ($res['comparison'] ?? [] as $row) {
            if ($row['field'] === 'accountExpires') {
                return $row;
            }
        }
        return null;
    }

    public function testNotConfiguredReturnsDisabledEnvelope(): void
    {
        $svc = new AdaxesService('', '', '', 5, fn() => null);
        self::assertFalse($svc->configured());

        $res = $svc->verify(['username' => 'jsmith'], []);
        self::assertFalse($res['ok']);
        self::assertFalse($res['configured']);
        self::assertStringContainsString('ADAXES_BASE_URL', (string) $res['error']);
    }

    public function testLooksUpByObjectGuidAndMatches(): void
    {
        $captured = null;
        $svc = $this->service($this->objectResponse([
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'jsmith@example.org',
            'mail'              => 'jsmith@example.org',
            'displayName'       => 'John Smith',
            'distinguishedName' => 'CN=John Smith,OU=Faculty,DC=example,DC=org',
            'accountDisabled'   => false,
        ]), $captured);

        $person = ['username' => 'jsmith', 'upn' => 'jsmith@example.org', 'email' => 'jsmith@example.org', 'status' => 'active'];
        $sourceIds = [['system' => 'ad', 'source_key' => 'a1b2c3d4-guid', 'is_active' => 1]];

        $res = $svc->verify($person, $sourceIds);

        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame('objectGUID', $res['by']);
        self::assertSame('a1b2c3d4-guid', $res['identifier']);
        // The GUID rides in the directoryObject query param (not a path segment),
        // and the token rides in the Adm-Authorization header.
        self::assertStringContainsString('/api/directoryObjects?', $captured['url']);
        self::assertStringContainsString('directoryObject=a1b2c3d4-guid', $captured['url']);
        self::assertStringContainsString('properties=', $captured['url']);
        self::assertSame('test-token', $captured['headers']['Adm-Authorization']);
        self::assertArrayNotHasKey('Authorization', $captured['headers']);
        // Every comparable field agrees.
        self::assertSame(0, AdaxesService::diffCount($res['comparison']));
    }

    public function testFlagsDifferingFields(): void
    {
        $svc = $this->service($this->objectResponse([
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'john.smith@example.org', // differs from golden
            'mail'              => 'jsmith@example.org',
            'accountDisabled'   => false,
        ]));

        $person = ['username' => 'jsmith', 'upn' => 'jsmith@example.org', 'email' => 'jsmith@example.org', 'status' => 'active'];
        $res = $svc->verify($person, [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        self::assertTrue($res['found']);
        $byField = [];
        foreach ($res['comparison'] as $row) {
            $byField[$row['field']] = $row['state'];
        }
        self::assertSame('differ', $byField['userPrincipalName']);
        self::assertSame('match', $byField['sAMAccountName']);
    }

    public function testDisabledAccountVsActiveStatusDiffers(): void
    {
        $svc = $this->service($this->objectResponse([
            'sAMAccountName'  => 'jsmith',
            'accountDisabled' => true,
        ]));

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        $state = null;
        foreach ($res['comparison'] as $row) {
            if ($row['field'] === 'accountDisabled') {
                $state = $row['state'];
            }
        }
        self::assertSame('differ', $state);
    }

    public function testExpiredAccountForActivePersonDiffers(): void
    {
        $svc = $this->service($this->objectResponse([
            'sAMAccountName' => 'jsmith',
            'accountExpires' => self::filetime('2000-06-15'),   // long past
        ]));

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        $row = self::expiryRowOf($res);
        self::assertNotNull($row);
        self::assertSame('2000-06-15', $row['ad']);
        self::assertSame('differ', $row['state']);   // active but the AD account already expired
    }

    public function testNeverExpiresWithNoGoldenEndDateMatches(): void
    {
        $svc = $this->service($this->objectResponse([
            'sAMAccountName' => 'jsmith',
            'accountExpires' => '0',                  // 0 = never expires
        ]));

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        $row = self::expiryRowOf($res);
        self::assertNotNull($row);
        self::assertSame('Never', $row['ad']);
        self::assertSame('', $row['golden']);
        self::assertSame('match', $row['state']);
    }

    public function testExpirationMatchesGoldenEndDate(): void
    {
        $svc = $this->service($this->objectResponse([
            'sAMAccountName' => 'jsmith',
            'accountExpires' => self::filetime('2099-06-30'),
        ]));

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active', 'end_date' => '2099-06-30'],
            [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        $row = self::expiryRowOf($res);
        self::assertNotNull($row);
        self::assertSame('2099-06-30', $row['ad']);
        self::assertSame('2099-06-30', $row['golden']);
        self::assertSame('match', $row['state']);
    }

    public function testFriendlyExpirationDateDiffersFromGoldenEndDate(): void
    {
        $svc = $this->service($this->objectResponse([
            'sAMAccountName'        => 'jsmith',
            'accountExpirationDate' => '2099-06-30T00:00:00Z',   // friendly form
        ]));

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active', 'end_date' => '2098-01-01'],
            [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        $row = self::expiryRowOf($res);
        self::assertNotNull($row);
        self::assertSame('2099-06-30', $row['ad']);
        self::assertSame('differ', $row['state']);   // AD expiry doesn't line up with the golden end date
    }

    public function testNoExpirationAttributeOmitsTheRow(): void
    {
        $svc = $this->service($this->objectResponse(['sAMAccountName' => 'jsmith']));

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        self::assertNull(self::expiryRowOf($res));   // AD returned no expiration -> no row
    }

    public function testUserAccountControlDisableBitIsHonored(): void
    {
        // 0x202 = NORMAL_ACCOUNT | ACCOUNTDISABLE → disabled.
        $svc = $this->service($this->objectResponse([
            'sAMAccountName'     => 'jsmith',
            'userAccountControl' => 514,
        ]));
        $res = $svc->verify(['username' => 'jsmith', 'status' => 'disabled'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);

        $state = null;
        foreach ($res['comparison'] as $row) {
            if ($row['field'] === 'accountDisabled') {
                $state = $row['state'];
            }
        }
        // Golden status 'disabled' expects disabled; AD is disabled → match.
        self::assertSame('match', $state);
    }

    public function testFallsBackToSearchPostsOrCriteriaOverAllThree(): void
    {
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return ['status' => 200, 'body' => json_encode([
                'objects' => [['properties' => ['sAMAccountName' => 'jsmith', 'mail' => 'jsmith@example.org']]],
            ])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, null, 'test-token');

        // No AD guid in the crosswalk → search by username/email/employee id.
        $res = $svc->verify(['username' => 'jsmith', 'email' => 'jsmith@example.org', 'employee_id' => '12345', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('search', $res['by']);
        // POST to the search endpoint with a JSON criteria body.
        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('/api/directoryObjects/search', $captured['url']);
        self::assertSame('application/json', $captured['headers']['Content-Type']);

        $sent = json_decode((string) $captured['body'], true);
        $group = $sent['criteria']['objectTypes'][0]['items'];
        self::assertSame(2, $group['logicalOperator']); // OR across the three
        $props = array_column($group['items'], 'property');
        self::assertSame(['sAMAccountName', 'mail', 'employeeID'], $props);
        self::assertSame('12345', $group['items'][2]['values'][0]['value']);
        self::assertSame('eq', $group['items'][0]['operator']);
    }

    public function testInactiveAdCrosswalkDoesNotMatchByObjectGuid(): void
    {
        // After an unlink (or a cleanup), the 'ad' crosswalk row is inactive. It
        // must NOT drive an objectGUID lookup — otherwise the person can never be
        // correlated to a different account. verify() falls through to a search.
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = ['method' => $method, 'url' => $url];
            return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => ['sAMAccountName' => 'jsmith']]]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, null, 'test-token');

        $res = $svc->verify(
            ['username' => 'jsmith', 'status' => 'active'],
            [['system' => 'ad', 'source_key' => '8aad594f-6f76-4de0-81ac-85ded4350674', 'is_active' => 0]],
        );

        self::assertTrue($res['found']);
        self::assertSame('search', $res['by']);                 // NOT objectGUID
        self::assertStringContainsString('/api/directoryObjects/search', $captured['url']);
    }

    public function testSingleCriterionUsesAndOperator(): void
    {
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = ['body' => $body];
            return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => ['sAMAccountName' => 'jsmith']]]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, null, 'test-token');

        // Only an employee id on file (no username/email yet) → one condition.
        $res = $svc->verify(['employee_id' => '12345', 'status' => 'active'], []);
        self::assertTrue($res['found']);
        $group = json_decode((string) $captured['body'], true)['criteria']['objectTypes'][0]['items'];
        self::assertCount(1, $group['items']);
        self::assertSame('employeeID', $group['items'][0]['property']);
        self::assertSame(1, $group['logicalOperator']); // single criterion → AND (no OR needed)
    }

    public function testEmployeeIdAttributeIsConfigurable(): void
    {
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = ['body' => $body];
            return ['status' => 200, 'body' => json_encode(['objects' => []])];
        };
        // employeeNumber instead of the default employeeID.
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, 'employeeNumber', 'test-token');
        $svc->verify(['employee_id' => '777', 'status' => 'active'], []);
        $group = json_decode((string) $captured['body'], true)['criteria']['objectTypes'][0]['items'];
        self::assertSame('employeeNumber', $group['items'][0]['property']);
        self::assertSame('777', $group['items'][0]['values'][0]['value']);
    }

    public function testNoKeyMeansNothingToVerify(): void
    {
        $svc = $this->service(null);
        $res = $svc->verify(['username' => '', 'email' => '', 'employee_id' => '', 'status' => 'pending'], []);
        self::assertTrue($res['ok']);
        self::assertFalse($res['found']);
        self::assertNull($res['by']);
    }

    public function testMissingObjectFallsBackToSearch(): void
    {
        // The crosswalk 'ad' key is not a real objectGUID, so get-object 404s;
        // verify() then searches by attributes and finds the account there.
        $urls = [];
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$urls): ?array {
            $urls[] = $url;
            if (str_contains($url, 'directoryObject=')) {
                return ['status' => 404, 'body' => 'not found'];
            }
            return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => ['sAMAccountName' => 'jsmith', 'mail' => 'jsmith@example.org']]]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, null, 'test-token');

        $res = $svc->verify(
            ['username' => 'jsmith', 'email' => 'jsmith@example.org', 'status' => 'active'],
            [['system' => 'ad', 'source_key' => 'jsmith', 'is_active' => 1]]
        );
        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame('search', $res['by']); // matched via the fallback, not the GUID
        self::assertStringContainsString('directoryObject=jsmith', $urls[0]); // tried the key first
    }

    public function testStaleKeyAndNoSearchCriteriaReportsNotFound(): void
    {
        // get-object 404s and there is nothing to search on → clean not-found.
        $svc = $this->service(['status' => 404, 'body' => 'not found']);
        $res = $svc->verify(['username' => '', 'email' => '', 'employee_id' => ''], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertTrue($res['ok']);
        self::assertFalse($res['found']);
        self::assertSame('objectGUID', $res['by']);
    }

    public function testRetriesTransientTransportFailureThenSucceeds(): void
    {
        // First call returns null (an "HTTP 0" timeout), second succeeds.
        $calls = 0;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$calls): ?array {
            $calls++;
            if ($calls === 1) {
                return null; // transient transport failure
            }
            return ['status' => 200, 'body' => json_encode(['properties' => [['name' => 'sAMAccountName', 'value' => 'jsmith']]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $fetch, null, null, null, null, 'test-token');

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'guid-1', 'is_active' => 1]]);
        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame(2, $calls); // retried once, then succeeded
    }

    public function testRetriesGatewayErrorButNotAuthOr404(): void
    {
        // 503 is transient (retried); a 401 is definitive (not retried).
        $calls = 0;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$calls): ?array {
            $calls++;
            return ['status' => 503, 'body' => 'gateway'];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $fetch, null, null, null, null, 'test-token');
        $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertSame(3, $calls); // default ADAXES_RETRY_ATTEMPTS=3, all transient

        $calls2 = 0;
        $fetch2 = function (string $method, string $url, array $headers, ?string $body) use (&$calls2): ?array {
            $calls2++;
            return ['status' => 401, 'body' => ''];
        };
        $svc2 = new AdaxesService('https://adx.example.org/restv2', '', '', 5, $fetch2, null, null, null, null, 'test-token');
        $svc2->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertSame(1, $calls2); // 401 is not retried
    }

    public function testUnreachableReturnsErrorEnvelope(): void
    {
        $svc = $this->service(null); // transport failure
        $res = $svc->verify(['username' => 'jsmith'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('unreachable', (string) $res['error']);
    }

    public function testRejectedCredentialsReported(): void
    {
        $svc = $this->service(['status' => 401, 'body' => '']);
        $res = $svc->verify(['username' => 'jsmith'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('credentials', (string) $res['error']);
    }

    public function testSearch404SurfacesEndpointHintAndPath(): void
    {
        // No objectGUID → search; a 404 from the search endpoint should produce
        // an actionable error naming the path and pointing at the config knobs.
        $svc = $this->service(['status' => 404, 'body' => 'Not Found']);
        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], []);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('HTTP 404', (string) $res['error']);
        self::assertStringContainsString('directoryObjects/search', (string) $res['error']); // the path that 404'd
        self::assertStringContainsString('ADAXES_SEARCH_PATH', (string) $res['error']);       // how to fix it
    }

    public function test3xxSurfacesRedirectLocation(): void
    {
        // A redirect (e.g. wrong path / login bounce) should name where Adaxes
        // tried to send us, which reveals a missing /api/ segment vs. a login.
        $svc = $this->service([
            'status'   => 302,
            'body'     => '',
            'location' => 'https://adx.example.org/restv2/api/directoryObjects/T13305',
        ]);
        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'T13305', 'is_active' => 1]]);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('HTTP 302', (string) $res['error']);
        self::assertStringContainsString('redirected to https://adx.example.org/restv2/api/directoryObjects/T13305', (string) $res['error']);
    }

    public function testNormalizesMapShapeAndMultiValue(): void
    {
        // Map-form properties + a multi-valued attribute.
        $svc = $this->service(['status' => 200, 'body' => json_encode([
            'properties' => [
                'sAMAccountName' => 'jsmith',
                'memberOf'       => ['CN=Staff', 'CN=Faculty'],
            ],
            'distinguishedName' => 'CN=John,OU=Faculty,DC=example,DC=org',
        ])]);

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertTrue($res['found']);
        // DN from the object body is surfaced as an info row.
        $dn = null;
        foreach ($res['comparison'] as $row) {
            if ($row['field'] === 'distinguishedname') {
                $dn = $row['ad'];
            }
        }
        self::assertSame('CN=John,OU=Faculty,DC=example,DC=org', $dn);
    }

    public function testFoundResultSurfacesObjectGuidForLinking(): void
    {
        // A match found via search (no GUID on file) exposes the objectGUID so the
        // caller can backfill the crosswalk.
        $fetch = function (string $method, string $url, array $headers, ?string $body): ?array {
            return ['status' => 200, 'body' => json_encode([
                'objects' => [['properties' => [
                    'objectGUID'     => '2b6160e2-ad91-419c-8960-cf672c75528f',
                    'sAMAccountName' => 'jsmith',
                ]]],
            ])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, null, 'test-token');

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], []); // no AD id → search
        self::assertTrue($res['found']);
        self::assertSame('2b6160e2-ad91-419c-8960-cf672c75528f', $res['guid']);
    }

    public function testNonGuidObjectGuidValueIsIgnored(): void
    {
        // A malformed/binary objectGUID must not be surfaced (we'd store junk).
        $svc = $this->service($this->objectResponse([
            'objectGUID'     => 'not-a-guid',
            'sAMAccountName' => 'jsmith',
        ]));
        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertTrue($res['found']);
        self::assertNull($res['guid']);
    }

    public function testConfiguredWithTokenOnly(): void
    {
        // A static token alone (no username/password) is enough to be configured.
        $svc = new AdaxesService('https://adx.example.org/restApi', '', '', 5, fn() => null, null, null, null, null, 'tok123');
        self::assertTrue($svc->configured());
    }

    public function testUsernamePasswordHandshakeObtainsAndUsesToken(): void
    {
        // No static token: the service must POST credentials to create a session,
        // exchange it for a token, then send that token as Adm-Authorization.
        $calls = [];
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$calls): ?array {
            $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            if (str_contains($url, '/api/authSessions/create')) {
                return ['status' => 200, 'body' => json_encode(['sessionId' => 'SESS-1', 'expiresAtUtc' => '2030-01-01T00:00:00Z'])];
            }
            if (str_contains($url, '/api/auth')) {
                return ['status' => 200, 'body' => json_encode(['token' => 'TOK-XYZ', 'expiresAtUtc' => '2030-01-01T00:00:00Z'])];
            }
            return ['status' => 200, 'body' => json_encode(['properties' => [['name' => 'sAMAccountName', 'value' => 'jsmith']]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restApi', 'svc', 'pw', 5, $fetch);

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'guid-1', 'is_active' => 1]]);

        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        // 1) create session (credentials in body), 2) obtain token (sessionId), 3) data request.
        self::assertStringContainsString('/api/authSessions/create', $calls[0]['url']);
        self::assertSame('POST', $calls[0]['method']);
        self::assertStringContainsString('"password":"pw"', (string) $calls[0]['body']);
        self::assertStringContainsString('/api/auth', $calls[1]['url']);
        self::assertStringContainsString('"sessionId":"SESS-1"', (string) $calls[1]['body']);
        // The data request carries the obtained token; no credentials in its body.
        self::assertStringContainsString('directoryObject=guid-1', $calls[2]['url']);
        self::assertSame('TOK-XYZ', $calls[2]['headers']['Adm-Authorization']);

        // Cleanup (best-effort): token destroyed, then session terminated.
        $deletes = array_values(array_filter($calls, static fn($c) => $c['method'] === 'DELETE'));
        self::assertCount(2, $deletes);
        self::assertStringContainsString('/api/auth?token=TOK-XYZ', $deletes[0]['url']);
        self::assertStringContainsString('/api/authSessions?id=SESS-1', $deletes[1]['url']);
    }

    public function testHandshakeFailureSurfacesAuthError(): void
    {
        // Session create rejects the credentials → a clear auth-failure envelope.
        $fetch = fn(string $m, string $u, array $h, ?string $b): array => ['status' => 401, 'body' => ''];
        $svc = new AdaxesService('https://adx.example.org/restApi', 'svc', 'pw', 5, $fetch);

        $res = $svc->verify(['username' => 'jsmith', 'status' => 'active'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('authentication failed', (string) $res['error']);
    }

    public function testGoldenCandidateMapsAdIdentityForLinking(): void
    {
        $svc = $this->service($this->objectResponse([
            'objectGUID'        => '11111111-2222-3333-4444-555555555555',
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'jsmith@example.org',
            'mail'              => 'john.smith@example.org',
            'accountDisabled'   => false,
        ]));

        $res = $svc->verify(['status' => 'pending'], [['system' => 'ad', 'source_key' => 'g', 'is_active' => 1]]);
        self::assertTrue($res['found']);

        // The shape linkAdAccount() consumes: sAMAccountName→username,
        // userPrincipalName→upn, mail→email, plus the objectGUID.
        $ad = AdaxesService::goldenCandidate($res);
        self::assertSame('11111111-2222-3333-4444-555555555555', $ad['guid']);
        self::assertSame('jsmith', $ad['username']);
        self::assertSame('jsmith@example.org', $ad['upn']);
        self::assertSame('john.smith@example.org', $ad['email']);
    }

    public function testMemberOfReturnsRawGroupDnsWithoutCommaCorruption(): void
    {
        // memberOf is multi-valued and each value is a DN full of commas — the
        // raw list must survive intact (not be flattened/split on commas).
        $captured = null;
        $svc = $this->service(['status' => 200, 'body' => json_encode([
            'properties' => [
                ['name' => 'memberOf', 'values' => [
                    'CN=All-Faculty,OU=Groups,DC=example,DC=org',
                    'CN=CO-Everyone,OU=Groups,DC=example,DC=org',
                ]],
            ],
        ])], $captured);

        $res = $svc->memberOf('2b6160e2-ad91-419c-8960-cf672c75528f');
        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame([
            'CN=All-Faculty,OU=Groups,DC=example,DC=org',
            'CN=CO-Everyone,OU=Groups,DC=example,DC=org',
        ], $res['groups']);
        self::assertStringContainsString('properties=memberOf', $captured['url']);
    }

    public function testMemberOfMissingObjectIsCleanNotFound(): void
    {
        $svc = $this->service(['status' => 404, 'body' => 'not found']);
        $res = $svc->memberOf('2b6160e2-ad91-419c-8960-cf672c75528f');
        self::assertTrue($res['ok']);
        self::assertFalse($res['found']);
        self::assertSame([], $res['groups']);
    }

    public function testGoldenCandidateEmptyWhenNoAttributes(): void
    {
        // A not-found / off envelope yields nothing to adopt.
        $ad = AdaxesService::goldenCandidate(['attributes' => [], 'guid' => null]);
        self::assertNull($ad['guid']);
        self::assertSame('', $ad['username']);
        self::assertSame('', $ad['upn']);
        self::assertSame('', $ad['email']);
    }
}
