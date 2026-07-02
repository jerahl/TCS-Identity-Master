<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\NotifyController;
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
}
