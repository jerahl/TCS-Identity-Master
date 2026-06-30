<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\AdUsernameImporter;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration-ish coverage for the Adaxes "Employee List" import path, driven by
 * an in-memory SQLite person table. Runs in --dry-run, so only the matching and
 * decision logic execute (no writes) — which keeps it portable (no MySQL, no
 * ON DUPLICATE KEY). Verifies match precedence (employee id → email) and the
 * apply / noop / conflict / skipped / no-person outcomes.
 */
final class AdEmployeeListImportTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE person (
            person_id INTEGER PRIMARY KEY, username TEXT, email TEXT, employee_id TEXT,
            username_locked INTEGER DEFAULT 0, status TEXT)');
        $seed = $db->prepare('INSERT INTO person (person_id, username, email, employee_id, username_locked, status)
            VALUES (:id, :u, :e, :emp, :lock, :st)');
        // A: matched by employee id, no username yet  -> apply
        $seed->execute([':id' => 1, ':u' => '', ':e' => 'amay1@tusc.k12.al.us', ':emp' => '15119', ':lock' => 0, ':st' => 'pending']);
        // B: no employee id on the row, matched by email -> apply
        $seed->execute([':id' => 2, ':u' => '', ':e' => 'abeale@tusc.k12.al.us', ':emp' => '', ':lock' => 0, ':st' => 'active']);
        // C: locked to a different username -> conflict
        $seed->execute([':id' => 3, ':u' => 'different', ':e' => 'clocked@tusc.k12.al.us', ':emp' => '200', ':lock' => 1, ':st' => 'active']);
        // D: username already correct -> noop
        $seed->execute([':id' => 4, ':u' => 'csame', ':e' => 'csame@tusc.k12.al.us', ':emp' => '300', ':lock' => 0, ':st' => 'active']);
        return $db;
    }

    private function csvFile(): string
    {
        $h = 'First name,Last name,Description,Job title,Email,Logon Name,Department,'
           . 'Logon Name (pre-Windows 2000),Employee ID,Parent,Name,Object GUID';
        $rows = [
            // first,last,desc,title,email,upn,dept,sam,empid,parent,name,guid
            'A,May,Teacher,Teacher,amay1@tusc.k12.al.us,amay1@tusc.k12.al.us,Sch,amay1,15119,MLK,A May,06f33027-aaaa',
            'Al,Beale,Teacher,Teacher,abeale@tusc.k12.al.us,abeale@tusc.k12.al.us,Sch,abeale,,ALB,Al Beale,2b74d126-bbbb',
            'Ghost,User,X,X,ghost@tusc.k12.al.us,ghost@tusc.k12.al.us,Sch,ghost,99999,CO,Ghost User,deadbeef-cccc',
            'No,Logon,X,X,nologon@tusc.k12.al.us,,Sch,,400,CO,No Logon,11111111-dddd',
            'C,Locked,X,X,clocked@tusc.k12.al.us,clocked@tusc.k12.al.us,Sch,other,200,CO,C Locked,22222222-eeee',
            'C,Same,X,X,csame@tusc.k12.al.us,csame@tusc.k12.al.us,Sch,csame,300,CO,C Same,33333333-ffff',
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'idm_emp');
        file_put_contents($tmp, $h . "\n" . implode("\n", $rows) . "\n");
        return $tmp;
    }

    public function testDryRunMatchingAndOutcomes(): void
    {
        $file = $this->csvFile();
        $result = (new AdUsernameImporter($this->db()))->run($file, dryRun: true);
        unlink($file);

        self::assertSame('employee_list', $result['format']);
        $c = $result['counts'];
        self::assertSame(6, $c['total']);
        self::assertSame(2, $c['applied'], 'A (by employee id) + B (by email)');
        self::assertSame(1, $c['no_person'], 'ghost matches nobody');
        self::assertSame(1, $c['skipped'], 'blank sAMAccountName row');
        self::assertSame(1, $c['conflict'], 'locked to a different username');
        self::assertSame(1, $c['noop'], 'username already correct');
        self::assertSame(0, $c['errors']);
    }
}
