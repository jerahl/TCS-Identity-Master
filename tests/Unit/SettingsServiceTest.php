<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config;
use App\Service\AuditService;
use App\Service\SettingsService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * SettingsService — the admin-editable config store. It must (a) write ONLY
 * whitelisted keys (the security boundary), (b) coerce per type, (c) treat a
 * blank as "revert to .env/default" for text but store explicit true/false for
 * booleans, (d) never overwrite a key pinned in the real environment, and
 * (e) layer its values into Config (under env, over .env). sqlite-backed.
 */
final class SettingsServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE app_setting (setting_key TEXT PRIMARY KEY, setting_value TEXT, updated_by TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    private function service(PDO $db): SettingsService
    {
        return new SettingsService($db, new AuditService($db));
    }

    protected function tearDown(): void
    {
        Config::overrides([]); // don't leak override state into other tests
    }

    public function testSavesOnlyWhitelistedKeys(): void
    {
        $db = $this->db();
        $changed = $this->service($db)->save([
            'AD_BASE_DN'    => 'DC=x,DC=y',
            'DB_APP_PASS'   => 'hacker',        // NOT whitelisted → ignored
            'RANDOM_KEY'    => 'nope',          // NOT whitelisted → ignored
        ], 'tester');

        $stored = $this->service($db)->stored();
        self::assertSame('DC=x,DC=y', $stored['AD_BASE_DN']);
        self::assertArrayNotHasKey('DB_APP_PASS', $stored);
        self::assertArrayNotHasKey('RANDOM_KEY', $stored);
        self::assertGreaterThanOrEqual(1, $changed);
    }

    public function testTypeCoercion(): void
    {
        $db = $this->db();
        $this->service($db)->save([
            'ADAXES_WRITE_MAX_CREATES'         => '25abc',   // int
            'ADAXES_WRITE_MAX_DISABLES_RATIO'  => '0.35',    // float
            'ADAXES_WRITE_ENABLED'             => 'true',    // bool
        ], 'tester');

        $stored = $this->service($db)->stored();
        self::assertSame('25', $stored['ADAXES_WRITE_MAX_CREATES']);
        self::assertSame('0.35', $stored['ADAXES_WRITE_MAX_DISABLES_RATIO']);
        self::assertSame('true', $stored['ADAXES_WRITE_ENABLED']);
    }

    public function testUncheckedBoolStoresFalseNotRevert(): void
    {
        $db = $this->db();
        // ADAXES_WRITE_ENABLED absent from input = unchecked → explicit 'false'.
        $this->service($db)->save(['AD_BASE_DN' => 'DC=x'], 'tester');
        self::assertSame('false', $this->service($db)->stored()['ADAXES_WRITE_ENABLED']);
    }

    public function testBlankTextRevertsByDeletingTheRow(): void
    {
        $db = $this->db();
        $svc = $this->service($db);
        $svc->save(['AD_BASE_DN' => 'DC=x'], 'tester');
        self::assertArrayHasKey('AD_BASE_DN', $svc->stored());

        $svc->save(['AD_BASE_DN' => ''], 'tester'); // blank → revert
        self::assertArrayNotHasKey('AD_BASE_DN', $this->service($db)->stored());
    }

    public function testEnvLockedKeyIsNeverOverwritten(): void
    {
        putenv('AD_BASE_DN=DC=env,DC=pinned');
        try {
            $db = $this->db();
            $this->service($db)->save(['AD_BASE_DN' => 'DC=web'], 'tester');
            // The web value must NOT be stored — ops pinned it in the environment.
            self::assertArrayNotHasKey('AD_BASE_DN', $this->service($db)->stored());
        } finally {
            putenv('AD_BASE_DN');
        }
    }

    public function testAuditRecordsEachChange(): void
    {
        $db = $this->db();
        $this->service($db)->save(['AD_BASE_DN' => 'DC=x'], 'alice');
        $row = $db->query("SELECT * FROM audit_log WHERE entity = 'config' AND after_json LIKE '%AD_BASE_DN%' LIMIT 1")->fetch();
        self::assertNotFalse($row);
        self::assertSame('alice', $row['actor']);
        self::assertStringContainsString('DC=x', (string) $row['after_json']);
    }

    public function testAppliesIntoConfigUnderEnvOverDotEnv(): void
    {
        $db = $this->db();
        $this->service($db)->save(['AD_PARENT_OU' => 'OU=Staff'], 'tester');

        Config::overrides($this->service($db)->stored());
        self::assertSame('OU=Staff', Config::get('AD_PARENT_OU'));

        // A real env var still wins over the stored override.
        putenv('AD_PARENT_OU=OU=EnvWins');
        try {
            self::assertSame('OU=EnvWins', Config::get('AD_PARENT_OU'));
        } finally {
            putenv('AD_PARENT_OU');
        }
    }

    public function testWhitelistExcludesSecrets(): void
    {
        $keys = SettingsService::whitelist();
        foreach (['ADAXES_TOKEN', 'ADAXES_WRITE_TOKEN', 'ADAXES_PASSWORD', 'DB_APP_PASS', 'DB_APP_USER'] as $secret) {
            self::assertNotContains($secret, $keys, "{$secret} must not be web-editable");
        }
        // Sanity: the tunable provisioning keys ARE present.
        self::assertContains('ADAXES_WRITE_ENABLED', $keys);
        self::assertContains('AD_BASE_DN', $keys);
        self::assertContains('AD_GROUP_M365_A1', $keys);
    }
}
