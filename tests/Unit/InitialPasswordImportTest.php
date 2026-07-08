<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\InitialPasswordImporter;
use App\Support\Crypto;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The password write-back path shared by the API and the one-time backfill:
 * header normalization, uuid/username matching, replace-on-resend, dry-run,
 * encrypted-at-rest storage, and no plaintext in audit rows or outcomes.
 */
final class InitialPasswordImportTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        putenv(Crypto::KEY_ENV . '=' . str_repeat('ab', 32));
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('CREATE TABLE person (person_id INTEGER PRIMARY KEY, person_uuid TEXT, username TEXT,
            initial_password_enc BLOB NULL, initial_password_set_at DATETIME NULL)');
        $this->db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INT, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT)');
        $this->db->exec('CREATE TABLE lifecycle_event (id INTEGER PRIMARY KEY, person_id INT, event_type TEXT,
            detail TEXT, actor TEXT)');
        $this->db->exec("INSERT INTO person (person_id, person_uuid, username) VALUES (1, 'uuid-1', 'jdoe')");
    }

    protected function tearDown(): void
    {
        putenv(Crypto::KEY_ENV);
    }

    public function testNormalizeRowAcceptsAliasesCaseInsensitively(): void
    {
        $r = InitialPasswordImporter::normalizeRow(['UniqueID' => 'u1', 'UserName' => 'jd', 'Temp_Password' => 'pw']);
        self::assertSame(['uniqueId' => 'u1', 'username' => 'jd', 'password' => 'pw'], $r);

        $r = InitialPasswordImporter::normalizeRow(['uuid' => ' u2 ', 'password' => 'pw2']);
        self::assertSame('u2', $r['uniqueId']);
        self::assertSame('pw2', $r['password']);
        self::assertSame('', $r['username']);
    }

    public function testAppliesByUuidEncryptedAtRest(): void
    {
        $imp = new InitialPasswordImporter($this->db);
        $r = $imp->applyEvent(['uniqueId' => 'uuid-1', 'password' => 'Falcon-42']);
        self::assertSame('applied', $r['outcome']);

        $row = $this->db->query('SELECT * FROM person WHERE person_id = 1')->fetch();
        self::assertNotNull($row['initial_password_set_at']);
        self::assertStringNotContainsString('Falcon-42', (string) $row['initial_password_enc']);
        self::assertSame('Falcon-42', Crypto::decrypt((string) $row['initial_password_enc']));
    }

    public function testMatchesByUsernameWhenNoUuid(): void
    {
        $imp = new InitialPasswordImporter($this->db);
        $r = $imp->applyEvent(['username' => 'jdoe', 'password' => 'pw']);
        self::assertSame('applied', $r['outcome']);
        $enc = $this->db->query('SELECT initial_password_enc FROM person WHERE person_id = 1')->fetchColumn();
        self::assertSame('pw', Crypto::decrypt((string) $enc));
    }

    public function testResendReplaces(): void
    {
        $imp = new InitialPasswordImporter($this->db);
        $imp->applyEvent(['uniqueId' => 'uuid-1', 'password' => 'old']);
        $r = $imp->applyEvent(['uniqueId' => 'uuid-1', 'password' => 'new']);
        self::assertSame('applied', $r['outcome']);
        self::assertSame('initial password replaced', $r['detail']);
        $enc = $this->db->query('SELECT initial_password_enc FROM person WHERE person_id = 1')->fetchColumn();
        self::assertSame('new', Crypto::decrypt((string) $enc));
    }

    public function testDryRunWritesNothing(): void
    {
        $imp = new InitialPasswordImporter($this->db);
        $r = $imp->applyEvent(['uniqueId' => 'uuid-1', 'password' => 'pw'], true);
        self::assertSame('applied', $r['outcome']);
        self::assertSame('would store initial password', $r['detail']);
        $row = $this->db->query('SELECT initial_password_enc, initial_password_set_at FROM person WHERE person_id = 1')->fetch();
        self::assertNull($row['initial_password_enc']);
        self::assertNull($row['initial_password_set_at']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
    }

    public function testSkipsAndNoPerson(): void
    {
        $imp = new InitialPasswordImporter($this->db);
        self::assertSame('skipped', $imp->applyEvent(['password' => 'pw'])['outcome']);
        self::assertSame('skipped', $imp->applyEvent(['uniqueId' => 'uuid-1', 'password' => '  '])['outcome']);
        self::assertSame('no_person', $imp->applyEvent(['uniqueId' => 'nope', 'password' => 'pw'])['outcome']);
        self::assertSame('no_person', $imp->applyEvent(['username' => 'nobody', 'password' => 'pw'])['outcome']);
    }

    public function testAuditAndOutcomesCarryNoPlaintext(): void
    {
        $imp = new InitialPasswordImporter($this->db);
        $r = $imp->applyEvent(['uniqueId' => 'uuid-1', 'password' => 'Sup3r-Secret']);
        self::assertStringNotContainsString('Sup3r-Secret', json_encode($r) ?: '');
        $audit = json_encode($this->db->query('SELECT * FROM audit_log')->fetchAll()) ?: '';
        $life = json_encode($this->db->query('SELECT * FROM lifecycle_event')->fetchAll()) ?: '';
        self::assertStringNotContainsString('Sup3r-Secret', $audit);
        self::assertStringNotContainsString('Sup3r-Secret', $life);
        self::assertStringContainsString('password_received', $life);
    }

    public function testRunProcessesPersonnelActionCsvAsIs(): void
    {
        // The HR board-approval export, unmodified: AD Login/AD Password are the
        // only columns that matter; everything else is ignored. Rows without a
        // login+password pair (e.g. a transfer) are skipped, not errors.
        $header = 'Change Type,Board Approval,Last,First Name MI,To Position,To,Effective Date,'
            . 'Empl #,DOB,G,R,AD Login,AD Password,PS Access,Email Address,ALSDE ID';
        $csv = tempnam(sys_get_temp_dir(), 'idm_test_pw_');
        self::assertNotFalse($csv);
        file_put_contents($csv, $header . "\n"
            . "New Hire,06/17/2026,Doe,Jane A,Teacher,Central HS,08/01/2026,1001,01/02/1990,F,W,jdoe,Falcon-42,Y,jdoe@example.org,123456789\n"
            . "Transfer,06/17/2026,Roe,Rick,Custodian,East ES,08/01/2026,1002,03/04/1985,M,B,,,N,rroe@example.org,987654321\n");
        try {
            $result = (new InitialPasswordImporter($this->db))->run($csv, false);
        } finally {
            @unlink($csv);
        }
        self::assertSame(2, $result['counts']['total']);
        self::assertSame(1, $result['counts']['applied']);
        self::assertSame(1, $result['counts']['skipped']);
        self::assertSame(0, $result['counts']['errors']);
        $enc = $this->db->query('SELECT initial_password_enc FROM person WHERE person_id = 1')->fetchColumn();
        self::assertSame('Falcon-42', Crypto::decrypt((string) $enc));
        self::assertStringNotContainsString('Falcon-42', json_encode($result['outcomes']) ?: '');
    }

    public function testRunProcessesCsv(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'idm_test_pw_');
        self::assertNotFalse($csv);
        file_put_contents($csv, "Username,Temp_Password\njdoe,Falcon-42\nnobody,pw\n,missing-user\n");
        try {
            $result = (new InitialPasswordImporter($this->db))->run($csv, false);
        } finally {
            @unlink($csv);
        }
        self::assertSame(3, $result['counts']['total']);
        self::assertSame(1, $result['counts']['applied']);
        self::assertSame(1, $result['counts']['no_person']);
        self::assertSame(1, $result['counts']['skipped']);
        $enc = $this->db->query('SELECT initial_password_enc FROM person WHERE person_id = 1')->fetchColumn();
        self::assertSame('Falcon-42', Crypto::decrypt((string) $enc));
        self::assertStringNotContainsString('Falcon-42', json_encode($result['outcomes']) ?: '');
    }
}
