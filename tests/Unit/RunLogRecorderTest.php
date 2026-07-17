<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\RunLogRecorder;
use PHPUnit\Framework\TestCase;

/**
 * RunLogRecorder's classifiers decide which sync progress events become
 * persisted service_run_log rows and at which severity — 'attention' feeds the
 * Outputs "requires attention" tile's filtered log view, 'change' the changes
 * tile, 'info' is context, and null (in-sync / no-op / skip noise) is dropped.
 * Pure functions: event in, entry-or-null out.
 */
final class RunLogRecorderTest extends TestCase
{
    // ---- Google events ----------------------------------------------------

    public function testGoogleScanErrorIsAttention(): void
    {
        $e = RunLogRecorder::fromGoogleEvent('scan', [
            'person_id' => 42, 'email' => 'p@x.org', 'bucket' => 'error',
            'action' => null, 'message' => 'API 503',
        ]);
        self::assertNotNull($e);
        self::assertSame('attention', $e['level']);
        self::assertSame('error', $e['outcome']);
        self::assertSame(42, $e['person_id']);
        self::assertSame('p@x.org', $e['subject']);
        self::assertSame('API 503', $e['detail']);
    }

    public function testGoogleScanLicenseBlockedIsAttention(): void
    {
        $e = RunLogRecorder::fromGoogleEvent('scan', [
            'person_id' => 7, 'email' => 'p@x.org', 'bucket' => 'license_blocked',
            'action' => null, 'detail' => '', 'message' => '',
        ]);
        self::assertNotNull($e);
        self::assertSame('attention', $e['level']);
        self::assertSame('license-blocked', $e['outcome']);
        self::assertSame('no license seat available', $e['detail']);
    }

    public function testGoogleScanManualOverrideIsInfo(): void
    {
        $e = RunLogRecorder::fromGoogleEvent('scan', [
            'person_id' => 7, 'email' => 'p@x.org', 'bucket' => 'manual_override',
        ]);
        self::assertNotNull($e);
        self::assertSame('info', $e['level']);
    }

    /** Planned actions surface as 'result' when applied — 'scan' must not double-log them. */
    public function testGoogleScanPlannedActionAndQuietBucketsAreDropped(): void
    {
        foreach ([
            ['bucket' => 'created', 'action' => 'create'],
            ['bucket' => 'pushed', 'action' => 'push'],
            ['bucket' => 'in_sync', 'action' => null],
            ['bucket' => 'no_email', 'action' => null],
            ['bucket' => 'no_account', 'action' => null],
        ] as $d) {
            self::assertNull(RunLogRecorder::fromGoogleEvent('scan', $d + ['person_id' => 1, 'email' => 'a@x.org']), $d['bucket']);
        }
    }

    public function testGoogleResultOkIsChange(): void
    {
        $e = RunLogRecorder::fromGoogleEvent('result', [
            'person_id' => 9, 'email' => 'n@x.org', 'action' => 'create',
            'detail' => 'new account in /tcs/faculty', 'ok' => true, 'message' => '',
        ]);
        self::assertNotNull($e);
        self::assertSame('change', $e['level']);
        self::assertSame('create', $e['outcome']);
        self::assertSame('apply', $e['phase']);
        self::assertSame('new account in /tcs/faculty', $e['detail']);
    }

    public function testGoogleResultFailureIsAttention(): void
    {
        $e = RunLogRecorder::fromGoogleEvent('result', [
            'person_id' => 9, 'email' => 'n@x.org', 'action' => 'suspend',
            'detail' => '', 'ok' => false, 'message' => 'quota exceeded',
        ]);
        self::assertNotNull($e);
        self::assertSame('attention', $e['level']);
        self::assertSame('suspend', $e['outcome']);
        self::assertSame('FAILED — quota exceeded', $e['detail']);
    }

    public function testGoogleUnknownEventIsDropped(): void
    {
        self::assertNull(RunLogRecorder::fromGoogleEvent('start', ['total' => 100]));
    }

    // ---- Adaxes items -----------------------------------------------------

    public function testAdaxesErrorReviewBlockedAreAttention(): void
    {
        foreach (['error', 'review', 'blocked'] as $outcome) {
            $e = RunLogRecorder::fromAdaxesItem([
                'person_id' => 3, 'name' => 'Pat Doe', 'action' => 'create',
                'outcome' => $outcome, 'detail' => 'why',
            ]);
            self::assertNotNull($e, $outcome);
            self::assertSame('attention', $e['level'], $outcome);
            self::assertSame($outcome, $e['outcome']);
            self::assertSame('create', $e['phase']);
            self::assertSame('Pat Doe', $e['subject']);
        }
    }

    public function testAdaxesAppliedOutcomesAreChanges(): void
    {
        foreach (['expired', 'edited', 'moved', 'created', 'correlated', 'rehired', 'password-set', 'synced'] as $outcome) {
            $e = RunLogRecorder::fromAdaxesItem([
                'person_id' => 3, 'name' => 'Pat Doe', 'action' => 'edit', 'outcome' => $outcome, 'detail' => '',
            ]);
            self::assertNotNull($e, $outcome);
            self::assertSame('change', $e['level'], $outcome);
        }
    }

    /** would-* previews (writes OFF) and capped candidates are context, not changes. */
    public function testAdaxesPreviewsAndCappedAreInfo(): void
    {
        foreach (['would-expire', 'would-edit', 'would-move', 'would-create', 'would-correlate', 'would-rehire', 'would-sync', 'capped'] as $outcome) {
            $e = RunLogRecorder::fromAdaxesItem([
                'person_id' => 3, 'name' => 'Pat Doe', 'action' => 'disable', 'outcome' => $outcome, 'detail' => '',
            ]);
            self::assertNotNull($e, $outcome);
            self::assertSame('info', $e['level'], $outcome);
        }
    }

    /** No-ops and not-in-AD skips are population-scale noise — counts cover them. */
    public function testAdaxesNoopAndSkipAreDropped(): void
    {
        foreach (['noop', 'skip'] as $outcome) {
            self::assertNull(RunLogRecorder::fromAdaxesItem([
                'person_id' => 3, 'name' => 'Pat Doe', 'action' => 'edit', 'outcome' => $outcome, 'detail' => '',
            ]), $outcome);
        }
    }
}
