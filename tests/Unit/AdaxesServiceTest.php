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
        return new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch);
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
        // The GUID and requested properties ride in the URL; auth header is Basic.
        self::assertStringContainsString('/directoryObjects/a1b2c3d4-guid', $captured['url']);
        self::assertStringContainsString('properties=', $captured['url']);
        self::assertStringStartsWith('Basic ', $captured['headers']['Authorization']);
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

    public function testFallsBackToSearchOnUsernameEmailAndEmployeeId(): void
    {
        $captured = null;
        $response = ['status' => 200, 'body' => json_encode([
            'objects' => [[
                'properties' => ['sAMAccountName' => 'jsmith', 'mail' => 'jsmith@example.org'],
            ]],
        ])];
        $fetch = function (string $method, string $url, array $headers, ?string $body) use ($response, &$captured): ?array {
            $captured = ['url' => $url];
            return $response;
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch);

        // No AD guid in the crosswalk → search by username/email/employee id.
        $res = $svc->verify(['username' => 'jsmith', 'email' => 'jsmith@example.org', 'employee_id' => '12345', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('search', $res['by']);
        self::assertStringContainsString('directorySearcher/search', $captured['url']);

        // The filter is an OR over all three attributes (URL-encoded).
        $filter = urldecode($captured['url']);
        self::assertStringContainsString('(|', $filter);
        self::assertStringContainsString('(sAMAccountName=jsmith)', $filter);
        self::assertStringContainsString('(mail=jsmith@example.org)', $filter);
        self::assertStringContainsString('(employeeID=12345)', $filter);
    }

    public function testSearchByEmployeeIdAloneUsesNoOrWrapper(): void
    {
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = ['url' => $url];
            return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => ['sAMAccountName' => 'jsmith']]]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch);

        // Only an employee id on file (no username/email yet).
        $res = $svc->verify(['employee_id' => '12345', 'status' => 'active'], []);
        self::assertTrue($res['found']);
        $filter = urldecode($captured['url']);
        self::assertStringContainsString('(employeeID=12345)', $filter);
        self::assertStringNotContainsString('(|', $filter); // single criterion → no OR
    }

    public function testEmployeeIdAttributeIsConfigurable(): void
    {
        $captured = null;
        $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
            $captured = ['url' => $url];
            return ['status' => 200, 'body' => json_encode(['objects' => []])];
        };
        // employeeNumber instead of the default employeeID.
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch, null, null, null, 'employeeNumber');
        $svc->verify(['employee_id' => '777', 'status' => 'active'], []);
        self::assertStringContainsString('(employeeNumber=777)', urldecode($captured['url']));
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
            if (str_contains($url, '/directoryObjects/')) {
                return ['status' => 404, 'body' => 'not found'];
            }
            return ['status' => 200, 'body' => json_encode(['objects' => [['properties' => ['sAMAccountName' => 'jsmith', 'mail' => 'jsmith@example.org']]]])];
        };
        $svc = new AdaxesService('https://adx.example.org/restv2', 'svc', 'pw', 5, $fetch);

        $res = $svc->verify(
            ['username' => 'jsmith', 'email' => 'jsmith@example.org', 'status' => 'active'],
            [['system' => 'ad', 'source_key' => 'jsmith', 'is_active' => 1]]
        );
        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame('search', $res['by']); // matched via the fallback, not the GUID
        self::assertStringContainsString('/directoryObjects/jsmith', $urls[0]); // tried the key first
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
}
