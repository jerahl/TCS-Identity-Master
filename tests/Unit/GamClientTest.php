<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\GamClient;
use PHPUnit\Framework\TestCase;

/**
 * The GAM command backend: it must (a) shape correct GAM7 argv for each
 * directory operation — argv-style so person data can never inject into a
 * shell, and with NO password on the command line (GAM's `password random`
 * instead), (b) parse formatjson output (info JSON, print CSV+JSON column)
 * back into Admin-SDK-shaped user arrays, (c) treat GAM's "Does not exist" as
 * a clean not-found, and (d) degrade to an ok=false envelope — never throw —
 * when gam is missing, times out, or errors. The process runner is injected so
 * no gam install is needed.
 */
final class GamClientTest extends TestCase
{
    /** A canned Admin-SDK-shaped user, as GAM's formatjson would print it. */
    private function user(array $overrides = []): array
    {
        return $overrides + [
            'id' => '1234', 'primaryEmail' => 'jsmith@x.org',
            'name' => ['givenName' => 'John', 'familyName' => 'Smith', 'fullName' => 'John Smith'],
            'suspended' => false, 'orgUnitPath' => '/Faculty',
            'externalIds' => [['value' => 'E1', 'type' => 'organization']],
        ];
    }

    /** Build a client whose runner replays canned results and records every argv. */
    private function client(array $results, ?array &$calls = null): GamClient
    {
        $calls = [];
        $runner = function (array $argv) use (&$calls, &$results): ?array {
            $calls[] = $argv;
            return array_shift($results);
        };
        return new GamClient(gamPath: '/usr/local/bin/gam', configDir: '', timeout: 5, runner: $runner);
    }

    private static function ok(string $stdout): array
    {
        return ['status' => 0, 'stdout' => $stdout, 'stderr' => ''];
    }

    public function testUnconfiguredWithoutGamPath(): void
    {
        $svc = new GamClient(gamPath: '', configDir: '', timeout: 5, runner: fn() => self::fail('must not run'));
        self::assertFalse($svc->configured());

        $res = $svc->getUser('jsmith@x.org');
        self::assertFalse($res['ok']);
        self::assertStringContainsString('GAM_PATH', (string) $res['error']);
    }

    public function testGetUserParsesInfoJson(): void
    {
        $calls = null;
        $svc = $this->client([self::ok((string) json_encode($this->user()))], $calls);

        $res = $svc->getUser('jsmith@x.org');

        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame('1234', $res['data']['id']);
        self::assertSame(['/usr/local/bin/gam', 'info', 'user', 'jsmith@x.org', 'formatjson'], $calls[0]);
    }

    public function testGetUserDoesNotExistIsCleanNotFound(): void
    {
        $svc = $this->client([['status' => 60, 'stdout' => '', 'stderr' => 'ERROR: User: nobody@x.org, Does not exist']]);

        $res = $svc->getUser('nobody@x.org');

        self::assertTrue($res['ok']); // mirrors the API backend's 404 → fall through to the next tier
        self::assertFalse($res['found']);
        self::assertNull($res['error']);
    }

    public function testGetUserOtherErrorSurfacesStderr(): void
    {
        $svc = $this->client([['status' => 2, 'stdout' => '', 'stderr' => 'ERROR: quota exceeded for quota metric']]);

        $res = $svc->getUser('jsmith@x.org');

        self::assertFalse($res['ok']);
        self::assertFalse($res['found']);
        self::assertStringContainsString('quota exceeded', (string) $res['error']);
        self::assertStringContainsString('exit 2', (string) $res['error']);
    }

    public function testSearchUsersParsesCsvJsonColumn(): void
    {
        $json = (string) json_encode($this->user());
        // GAM print … formatjson: CSV whose JSON column holds the resource (quotes doubled per CSV).
        $csv = "primaryEmail,JSON\n" . 'jsmith@x.org,"' . str_replace('"', '""', $json) . '"' . "\n";
        $calls = null;
        $svc = $this->client([self::ok($csv)], $calls);

        $res = $svc->searchUsers("externalId='E1'");

        self::assertTrue($res['ok']);
        self::assertTrue($res['found']);
        self::assertSame('jsmith@x.org', $res['data']['primaryEmail']);
        self::assertSame('E1', $res['data']['externalIds'][0]['value']);
        self::assertSame(['/usr/local/bin/gam', 'print', 'users', 'query', "externalId='E1'", 'allfields', 'formatjson'], $calls[0]);
    }

    public function testSearchUsersHeaderOnlyMeansNotFound(): void
    {
        $svc = $this->client([self::ok("primaryEmail,JSON\n")]);

        $res = $svc->searchUsers("givenName='Ghost' familyName='Nobody'");

        self::assertTrue($res['ok']);
        self::assertFalse($res['found']);
    }

    public function testCreateUserNeverPutsPasswordOnArgv(): void
    {
        $calls = null;
        $svc = $this->client([
            self::ok(''),                                     // gam create user …
            self::ok((string) json_encode($this->user())),    // read-back: gam info user …
        ], $calls);
        $body = [
            'primaryEmail' => 'jsmith@x.org',
            'name' => ['givenName' => 'John', 'familyName' => 'Smith'],
            'password' => 'Sup3r-Secret!', 'changePasswordAtNextLogin' => true,
            'suspended' => false, 'orgUnitPath' => '/Faculty',
            'externalIds' => [['value' => 'E1', 'type' => 'organization']],
        ];

        $res = $svc->createUser($body);

        self::assertTrue($res['ok']);
        self::assertSame('1234', $res['data']['id']); // id from the read-back → crosswalk
        $create = $calls[0];
        self::assertSame(['/usr/local/bin/gam', 'create', 'user', 'jsmith@x.org'], array_slice($create, 0, 4));
        self::assertNotContains('Sup3r-Secret!', $create); // secret never on a command line
        self::assertContains('random', $create);           // … GAM generates it instead
        self::assertSame(['firstname', 'John', 'lastname', 'Smith', 'org', '/Faculty',
            'externalid', 'organization', 'E1', 'password', 'random', 'changepassword', 'on'], array_slice($create, 4));
        self::assertSame(['/usr/local/bin/gam', 'info', 'user', 'jsmith@x.org', 'formatjson'], $calls[1]);
    }

    public function testCreateReadBackFailureStillReportsSuccess(): void
    {
        // The create wrote; only the follow-up info failed (e.g. propagation lag).
        $svc = $this->client([
            self::ok(''),
            ['status' => 60, 'stdout' => '', 'stderr' => 'ERROR: User: jsmith@x.org, Does not exist'],
        ]);

        $res = $svc->createUser(['primaryEmail' => 'jsmith@x.org', 'name' => ['givenName' => 'John', 'familyName' => 'Smith'], 'password' => 'x']);

        self::assertTrue($res['ok']);
        self::assertSame('jsmith@x.org', $res['data']['primaryEmail']);
        self::assertFalse($res['data']['suspended']);
        self::assertArrayNotHasKey('password', $res['data']); // never echo the secret back
    }

    public function testUpdateUserSuspendUsesOnOff(): void
    {
        $calls = null;
        $svc = $this->client([
            self::ok(''),
            self::ok((string) json_encode($this->user(['suspended' => true]))),
        ], $calls);

        $res = $svc->updateUser('1234', ['suspended' => true]);

        self::assertTrue($res['ok']);
        self::assertTrue($res['data']['suspended']);
        self::assertSame(['/usr/local/bin/gam', 'update', 'user', '1234', 'suspended', 'on'], $calls[0]);
    }

    public function testUpdateUserMapsNameOuAndExternalId(): void
    {
        $calls = null;
        $svc = $this->client([self::ok(''), self::ok((string) json_encode($this->user()))], $calls);

        $res = $svc->updateUser('1234', [
            'name' => ['givenName' => 'Jane', 'familyName' => 'Doe'],
            'orgUnitPath' => '/Staff',
            'externalIds' => [['value' => 'E9', 'type' => 'organization']],
        ]);

        self::assertTrue($res['ok']);
        self::assertSame(['/usr/local/bin/gam', 'update', 'user', '1234',
            'firstname', 'Jane', 'lastname', 'Doe', 'org', '/Staff', 'externalid', 'organization', 'E9'], $calls[0]);
    }

    public function testRunnerFailureNeverThrows(): void
    {
        $svc = $this->client([null]); // gam missing / timed out

        $res = $svc->getUser('jsmith@x.org');

        self::assertFalse($res['ok']);
        self::assertStringContainsString('GAM did not run', (string) $res['error']);
    }

    public function testExit127ReportsBinaryNotFound(): void
    {
        // exit 127 = the gam binary couldn't be executed (not on PATH / wrong
        // GAM_PATH). The message must say so and surface GAM_PATH, not leave a
        // bare "(exit 127): no output".
        $svc = $this->client([['status' => 127, 'stdout' => '', 'stderr' => '']]);

        $res = $svc->getUser('jsmith@x.org');

        self::assertFalse($res['ok']);
        self::assertStringContainsString('exit 127', (string) $res['error']);
        self::assertStringContainsString('not found or is not executable', (string) $res['error']);
        self::assertStringContainsString('/usr/local/bin/gam', (string) $res['error']);
    }

    public function testUnreachableMessageNamesProcFunctionsWhenDisabled(): void
    {
        // A host with any of the proc_* family in disable_functions can never
        // launch GAM — the message must point at that (and the API-backend escape
        // hatch), not at GAM_PATH.
        $msg = GamClient::unreachableMessage(false, '/usr/local/bin/gam');
        self::assertStringContainsString('proc_close', $msg);   // not just proc_open
        self::assertStringContainsString('GOOGLE_BACKEND=api', $msg);
        self::assertStringNotContainsString('GAM_PATH', $msg);
    }

    public function testUnreachableMessageNamesGamPathWhenProcessesRun(): void
    {
        $msg = GamClient::unreachableMessage(true, '/usr/local/bin/gam');
        self::assertStringContainsString('GAM did not run', $msg);
        self::assertStringContainsString('/usr/local/bin/gam', $msg);
    }

    public function testCanRunProcessesTrueInTestEnvironment(): void
    {
        // The test runner has the proc_* family enabled; the guard must not misreport it.
        self::assertTrue(GamClient::canRunProcesses());
    }
}
