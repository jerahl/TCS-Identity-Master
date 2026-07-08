<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\NotifyController;
use App\Support\Crypto;
use PHPUnit\Framework\TestCase;

/**
 * The pure decision logic behind orientation-checklist generation: which variant a
 * person gets, and whether they're ready for one. DB-free (static helpers).
 */
final class NotifyControllerTest extends TestCase
{
    public function testFacultyGetsNewTeacherChecklist(): void
    {
        self::assertSame('new_teacher', NotifyController::documentFor('faculty'));
    }

    public function testNonFacultyGetsNonInstructionalChecklist(): void
    {
        foreach (['staff', 'contractor', 'sub', 'intern', 'other'] as $type) {
            self::assertSame('non_instructional', NotifyController::documentFor($type), $type);
        }
    }

    public function testValidOverrideWins(): void
    {
        self::assertSame('non_instructional', NotifyController::documentFor('faculty', 'non_instructional'));
        self::assertSame('new_teacher', NotifyController::documentFor('staff', 'new_teacher'));
    }

    public function testInvalidOverrideFallsBackToType(): void
    {
        self::assertSame('new_teacher', NotifyController::documentFor('faculty', 'garbage'));
        self::assertSame('non_instructional', NotifyController::documentFor('staff', ''));
    }

    public function testReadyOnlyWhenUsernameMintedAndLocked(): void
    {
        self::assertTrue(NotifyController::isReady(['username' => 'jdoe', 'username_locked' => 1]));
    }

    public function testNotReadyWithoutLock(): void
    {
        self::assertFalse(NotifyController::isReady(['username' => 'jdoe', 'username_locked' => 0]));
    }

    public function testNotReadyWithoutUsername(): void
    {
        self::assertFalse(NotifyController::isReady(['username' => '', 'username_locked' => 1]));
        self::assertFalse(NotifyController::isReady(['username_locked' => 1]));
    }

    public function testInitialPasswordDecryptsStoredBlob(): void
    {
        putenv(Crypto::KEY_ENV . '=' . str_repeat('cd', 32));
        try {
            $enc = Crypto::encrypt('Falcon-Maple-42');
            self::assertSame('Falcon-Maple-42', NotifyController::initialPasswordFor(['initial_password_enc' => $enc]));
        } finally {
            putenv(Crypto::KEY_ENV);
        }
    }

    public function testInitialPasswordEmptyWhenAbsentOrUnreadable(): void
    {
        // No password stored.
        self::assertSame('', NotifyController::initialPasswordFor([]));
        self::assertSame('', NotifyController::initialPasswordFor(['initial_password_enc' => null]));

        // Stored under one key but read after a rotation — degrade to ''.
        putenv(Crypto::KEY_ENV . '=' . str_repeat('cd', 32));
        $enc = Crypto::encrypt('secret');
        putenv(Crypto::KEY_ENV . '=' . str_repeat('ef', 32));
        try {
            self::assertSame('', NotifyController::initialPasswordFor(['initial_password_enc' => $enc]));
        } finally {
            putenv(Crypto::KEY_ENV);
        }
    }
}
