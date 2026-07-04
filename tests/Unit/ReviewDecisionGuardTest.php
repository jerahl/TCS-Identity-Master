<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReviewService;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Compare-and-swap guards on the review decisions. confirm()/reject() must claim
 * the staged case (status = needs_review) and, for confirm, verify the target was
 * an actual pending candidate BEFORE any person is written. This stops:
 *   - replays / double-clicks / two admins racing the same case, and
 *   - a hand-crafted person id being grafted onto an arbitrary golden record.
 *
 * These guards fire before the (MySQL-only) PersonWriter calls, so they can be
 * exercised against in-memory SQLite: a valid decision would proceed into writer
 * SQL, but every case here is meant to abort at the guard and roll back.
 */
final class ReviewDecisionGuardTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec(
            'CREATE TABLE staging_record (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                batch_id INTEGER NULL,
                system TEXT NOT NULL,
                raw_json TEXT NULL,
                n_first TEXT NULL, n_last TEXT NULL, n_dob TEXT NULL,
                n_employee_id TEXT NULL, n_source_key TEXT NULL, n_school_code TEXT NULL,
                match_status TEXT NOT NULL DEFAULT "new",
                matched_person_id INTEGER NULL,
                reason TEXT NULL
            )'
        );
        $this->db->exec(
            'CREATE TABLE match_candidate (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                staging_id INTEGER NOT NULL,
                candidate_person_id INTEGER NOT NULL,
                score REAL NULL, match_basis TEXT NULL,
                status TEXT NOT NULL DEFAULT "pending",
                decided_by TEXT NULL, decided_at TEXT NULL
            )'
        );
        $this->db->exec('CREATE TABLE person (person_id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
    }

    private function service(): ReviewService
    {
        return new ReviewService($this->db);
    }

    /** Stage a row and (optionally) a candidate; returns the staging id. */
    private function stage(string $matchStatus, ?int $candidatePersonId, string $candidateStatus = 'pending'): int
    {
        $this->db->prepare(
            'INSERT INTO staging_record (system, raw_json, n_first, n_last, n_source_key, match_status)
             VALUES ("nextgen", NULL, "Jane", "Doe", "SK-1", ?)'
        )->execute([$matchStatus]);
        $sid = (int) $this->db->lastInsertId();
        if ($candidatePersonId !== null) {
            $this->db->prepare('INSERT INTO match_candidate (staging_id, candidate_person_id, status) VALUES (?, ?, ?)')
                ->execute([$sid, $candidatePersonId, $candidateStatus]);
        }
        return $sid;
    }

    public function testConfirmOnAlreadyResolvedCaseAbortsAndWritesNothing(): void
    {
        // The case was already merged; a replayed confirm must not re-run.
        $sid = $this->stage('merged', 5, 'confirmed');

        try {
            $this->service()->confirm($sid, 5, 'admin@x');
            self::fail('expected confirm to abort on an already-resolved case');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('already been resolved', $e->getMessage());
        }

        // Nothing changed (transaction rolled back).
        self::assertSame('merged', $this->db->query("SELECT match_status FROM staging_record WHERE id = $sid")->fetchColumn());
        self::assertSame('confirmed', $this->db->query("SELECT status FROM match_candidate WHERE staging_id = $sid")->fetchColumn());
    }

    public function testConfirmWithNonCandidatePersonAbortsBeforeAnyWrite(): void
    {
        // Case is open with a pending candidate for person 5; an attacker submits a
        // different person id (99) that was never a candidate.
        $sid = $this->stage('needs_review', 5, 'pending');

        try {
            $this->service()->confirm($sid, 99, 'admin@x');
            self::fail('expected confirm to reject a non-candidate person id');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('not a pending match candidate', $e->getMessage());
        }

        // The staged row is still open and the real candidate still pending — the
        // whole transaction (including the status claim) rolled back.
        self::assertSame('needs_review', $this->db->query("SELECT match_status FROM staging_record WHERE id = $sid")->fetchColumn());
        self::assertSame('pending', $this->db->query("SELECT status FROM match_candidate WHERE staging_id = $sid")->fetchColumn());
    }

    public function testRejectOnAlreadyResolvedCaseCreatesNoDuplicatePerson(): void
    {
        // The case was already rejected (status 'new'); a replayed reject would
        // otherwise mint a second person for the same staged row.
        $sid = $this->stage('new', null);

        try {
            $this->service()->reject($sid, 'admin@x');
            self::fail('expected reject to abort on an already-resolved case');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('already been resolved', $e->getMessage());
        }

        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM person')->fetchColumn(), 'no person created on a replayed reject');
    }

    public function testMissingStagedRowThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->confirm(4242, 5, 'admin@x');
    }
}
