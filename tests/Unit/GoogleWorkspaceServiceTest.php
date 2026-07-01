<?php

declare(strict_types=1);

namespace App\Tests\Unit;

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
