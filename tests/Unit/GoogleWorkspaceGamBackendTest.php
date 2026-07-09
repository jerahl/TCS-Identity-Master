<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GamClient;
use App\Service\GoogleWorkspaceService;
use PHPUnit\Framework\TestCase;

/**
 * GoogleWorkspaceService with GOOGLE_BACKEND=gam: the shared behavior —
 * correlation tiers, comparison, envelopes, the golden-email create guard —
 * must be IDENTICAL to the API backend, with only the transport swapped to GAM
 * commands. Also pins that gam mode needs no service-account credentials and
 * that the off-message points at GAM_PATH rather than GOOGLE_SA_*.
 */
final class GoogleWorkspaceGamBackendTest extends TestCase
{
    private function user(array $overrides = []): array
    {
        return $overrides + [
            'id' => '1234', 'primaryEmail' => 'jsmith@x.org',
            'name' => ['givenName' => 'John', 'familyName' => 'Smith', 'fullName' => 'John Smith'],
            'suspended' => false, 'orgUnitPath' => '/Faculty',
            'externalIds' => [['value' => 'E1', 'type' => 'organization']],
        ];
    }

    /** Service in gam mode (no SA credentials at all), with a scripted runner. */
    private function service(array $results, ?array &$calls = null): GoogleWorkspaceService
    {
        $calls = [];
        $runner = function (array $argv) use (&$calls, &$results): ?array {
            $calls[] = $argv;
            return array_shift($results);
        };
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: $runner);
        return new GoogleWorkspaceService(enabled: true, fetch: fn() => self::fail('gam mode must not use HTTP'), gam: $gam);
    }

    private static function ok(string $stdout): array
    {
        return ['status' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }

    public function testConfiguredWithoutServiceAccountCredentials(): void
    {
        $svc = $this->service([]);
        self::assertTrue($svc->configured());
        self::assertSame('gam', $svc->backend());
    }

    public function testOffMessageMentionsGamPath(): void
    {
        $gam = new GamClient(gamPath: '', configDir: '', timeout: 5, runner: fn() => null);
        $svc = new GoogleWorkspaceService(enabled: true, fetch: fn() => null, gam: $gam);

        $res = $svc->correlate(['email' => 'jsmith@x.org'], []);

        self::assertFalse($res['ok']);
        self::assertFalse($res['configured']);
        self::assertStringContainsString('GAM_PATH', (string) $res['error']);
        self::assertStringContainsString('GOOGLE_DIRECT_ENABLED', (string) $res['error']);
    }

    public function testCorrelatesByEmailViaGamInfo(): void
    {
        $calls = null;
        $svc = $this->service([self::ok((string) json_encode($this->user()))], $calls);

        $res = $svc->correlate(['email' => 'jsmith@x.org', 'first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active'], []);

        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertTrue($res['auto']);
        self::assertSame('email', $res['by']);
        self::assertSame('1234', $res['googleId']);
        self::assertSame(0, GoogleWorkspaceService::diffCount($res['comparison']));
        self::assertSame(['gam', 'info', 'user', 'jsmith@x.org', 'formatjson'], $calls[0]);
    }

    public function testCorrelatesByGoogleDomainEmailDerivedFromUsername(): void
    {
        // Golden email/UPN are on-prem (@tusc.k12.al.us); the Google account is
        // username@GOOGLE_DOMAIN. Correlation must try the re-homed address FIRST.
        $calls = [];
        $runner = function (array $argv) use (&$calls): ?array {
            $calls[] = $argv;
            return ($argv[3] ?? '') === 'jsmith@tuscaloosacityschools.com'
                ? self::ok((string) json_encode($this->user(['primaryEmail' => 'jsmith@tuscaloosacityschools.com'])))
                : ['status' => 60, 'stdout' => '', 'stderr' => 'ERROR: Does not exist'];
        };
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: $runner);
        $svc = new GoogleWorkspaceService(enabled: true, domain: 'tuscaloosacityschools.com',
            fetch: fn() => self::fail('gam mode must not use HTTP'), gam: $gam);

        $res = $svc->correlate([
            'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
            'first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active',
        ], []);

        self::assertTrue($res['found']);
        self::assertSame('email', $res['by']);
        self::assertTrue($res['auto']);
        self::assertSame('jsmith@tuscaloosacityschools.com', $res['primaryEmail']);
        // First lookup used the Google-domain address, not the on-prem golden email.
        self::assertSame(['gam', 'info', 'user', 'jsmith@tuscaloosacityschools.com', 'formatjson'], $calls[0]);
    }

    public function testDerivesGoogleEmailFromLocalPartWhenNoUsername(): void
    {
        // No username on the record — the golden email's local part is re-homed
        // to GOOGLE_DOMAIN so correlation still targets the real Google account.
        $calls = [];
        $runner = function (array $argv) use (&$calls): ?array {
            $calls[] = $argv;
            return ($argv[3] ?? '') === 'jdoe@tuscaloosacityschools.com'
                ? self::ok((string) json_encode($this->user(['primaryEmail' => 'jdoe@tuscaloosacityschools.com'])))
                : ['status' => 60, 'stdout' => '', 'stderr' => 'ERROR: Does not exist'];
        };
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: $runner);
        $svc = new GoogleWorkspaceService(enabled: true, domain: 'tuscaloosacityschools.com',
            fetch: fn() => self::fail('gam mode must not use HTTP'), gam: $gam);

        $res = $svc->correlate(['email' => 'jdoe@tusc.k12.al.us', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('jdoe@tuscaloosacityschools.com', $calls[0][3]);
    }

    public function testComparisonUsesGoogleEmailNotOnPremEmail(): void
    {
        $runner = fn(array $argv): ?array => ($argv[3] ?? '') === 'jsmith@tuscaloosacityschools.com'
            ? self::ok((string) json_encode($this->user(['primaryEmail' => 'jsmith@tuscaloosacityschools.com'])))
            : ['status' => 60, 'stdout' => '', 'stderr' => 'ERROR: Does not exist'];
        $gam = new GamClient(gamPath: 'gam', configDir: '', timeout: 5, runner: $runner);
        $svc = new GoogleWorkspaceService(enabled: true, domain: 'tuscaloosacityschools.com',
            fetch: fn() => self::fail('gam mode must not use HTTP'), gam: $gam);

        $res = $svc->correlate([
            'username' => 'jsmith', 'email' => 'jsmith@tusc.k12.al.us', 'upn' => 'jsmith@tusc.k12.al.us',
            'first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active',
        ], []);

        $emailRow = null;
        foreach ($res['comparison'] as $row) {
            if ($row['field'] === 'primaryEmail') {
                $emailRow = $row;
            }
        }
        self::assertNotNull($emailRow);
        self::assertSame('Google email', $emailRow['label']);
        // Golden side is the derived Google email, not the on-prem @tusc.k12.al.us.
        self::assertSame('jsmith@tuscaloosacityschools.com', $emailRow['golden']);
        self::assertSame('match', $emailRow['state']);
    }

    public function testExternalIdTierRoutesThroughGamPrint(): void
    {
        $json = str_replace('"', '""', (string) json_encode($this->user()));
        $calls = null;
        $svc = $this->service([self::ok("primaryEmail,JSON\njsmith@x.org,\"{$json}\"\n")], $calls);

        // No email/upn → the search tier.
        $res = $svc->correlate(['employee_id' => 'E1', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('externalId', $res['by']);
        self::assertTrue($res['auto']);
        self::assertSame(['gam', 'print', 'users', 'query', "externalId='E1'", 'allfields', 'formatjson'], $calls[0]);
    }

    public function testNameMatchStillNeverAutoLinks(): void
    {
        $json = str_replace('"', '""', (string) json_encode($this->user()));
        $svc = $this->service([self::ok("primaryEmail,JSON\njsmith@x.org,\"{$json}\"\n")]);

        $res = $svc->correlate(['first_name' => 'John', 'last_name' => 'Smith', 'status' => 'active'], []);

        self::assertTrue($res['found']);
        self::assertSame('name', $res['by']);
        self::assertFalse($res['auto']);
    }

    public function testCreateStillRequiresGoldenEmail(): void
    {
        $calls = null;
        $svc = $this->service([], $calls);

        $res = $svc->createUser(['first_name' => 'John', 'last_name' => 'Smith'], null);

        self::assertFalse($res['ok']);
        self::assertStringContainsString('golden email', (string) $res['error']);
        self::assertSame([], $calls); // gam never invoked
    }

    public function testSuspendRunsGamSuspendedOn(): void
    {
        $calls = null;
        $svc = $this->service([
            self::ok(''),
            self::ok((string) json_encode($this->user(['suspended' => true]))),
        ], $calls);

        $res = $svc->suspendUser('1234');

        self::assertTrue($res['ok']);
        self::assertTrue($res['suspended']);
        self::assertSame('1234', $res['googleId']);
        self::assertSame(['gam', 'update', 'user', '1234', 'suspended', 'on'], $calls[0]);
    }

    public function testGamFailureDegradesToEnvelope(): void
    {
        $svc = $this->service([null]); // gam missing / timed out

        $res = $svc->correlate(['email' => 'jsmith@x.org', 'status' => 'active'], []);

        self::assertFalse($res['ok']);
        self::assertTrue($res['configured']);
        self::assertStringContainsString('GAM did not run', (string) $res['error']);
    }
}
