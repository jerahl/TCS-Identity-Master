<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\Importer;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Review-queue idempotency: a row awaiting human review carries no source id, so
 * a naive re-import re-stages it and queues a second pending case for the same
 * person — duplicating it in the queue. The importer guards against that by
 * skipping any incoming source that already has a pending review case.
 *
 * This exercises the guard (Importer::hasPendingReview) directly against a
 * minimal SQLite mirror of the two tables it consults, so we don't have to stand
 * up the whole ingestion pipeline.
 */
final class ReviewDedupeTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE staging_record (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                system TEXT NOT NULL,
                n_source_key TEXT NULL,
                match_status TEXT NOT NULL DEFAULT "new"
            )'
        );
        $this->db->exec(
            'CREATE TABLE match_candidate (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                staging_id INTEGER NOT NULL,
                candidate_person_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT "pending"
            )'
        );
    }

    private function stage(string $system, string $sourceKey, string $candidateStatus): int
    {
        $this->db->prepare('INSERT INTO staging_record (system, n_source_key, match_status) VALUES (?, ?, "needs_review")')
            ->execute([$system, $sourceKey]);
        $stagingId = (int) $this->db->lastInsertId();
        $this->db->prepare('INSERT INTO match_candidate (staging_id, candidate_person_id, status) VALUES (?, 1, ?)')
            ->execute([$stagingId, $candidateStatus]);
        return $stagingId;
    }

    private function hasPendingReview(string $system, string $sourceKey, int $excludeStagingId): bool
    {
        return Importer::hasPendingReview($this->db, $system, $sourceKey, $excludeStagingId);
    }

    public function testDetectsExistingPendingReviewForSameSource(): void
    {
        $this->stage('powerschool', 'PS-100', 'pending');
        self::assertTrue($this->hasPendingReview('powerschool', 'PS-100', 0));
    }

    public function testIgnoresResolvedCases(): void
    {
        // A confirmed/rejected case must NOT block a fresh re-import of the source.
        $this->stage('powerschool', 'PS-200', 'confirmed');
        $this->stage('nextgen', 'NG-7', 'rejected');
        self::assertFalse($this->hasPendingReview('powerschool', 'PS-200', 0));
        self::assertFalse($this->hasPendingReview('nextgen', 'NG-7', 0));
    }

    public function testScopedBySystemAndSourceKey(): void
    {
        $this->stage('powerschool', 'PS-300', 'pending');
        // Same key, different system — different incoming record, not a duplicate.
        self::assertFalse($this->hasPendingReview('nextgen', 'PS-300', 0));
        // Same system, different key.
        self::assertFalse($this->hasPendingReview('powerschool', 'PS-999', 0));
    }

    public function testExcludesTheJustInsertedStagingRow(): void
    {
        $stagingId = $this->stage('powerschool', 'PS-400', 'pending');
        // Excluding the only pending row means there is no *other* pending case.
        self::assertFalse($this->hasPendingReview('powerschool', 'PS-400', $stagingId));
    }

    public function testBlankSourceKeyIsNeverADuplicate(): void
    {
        self::assertFalse($this->hasPendingReview('powerschool', '', 0));
        self::assertFalse($this->hasPendingReview('powerschool', '   ', 0));
    }
}
