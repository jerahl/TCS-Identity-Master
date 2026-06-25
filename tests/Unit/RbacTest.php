<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AuthService;
use PHPUnit\Framework\TestCase;

/**
 * Role/capability matrix — the server-side RBAC gate. readonly can only view;
 * editor can edit but not administer; admin can do everything.
 */
final class RbacTest extends TestCase
{
    public function testReadonlyCanOnlyView(): void
    {
        self::assertTrue(AuthService::roleHasCapability('readonly', 'view'));
        self::assertFalse(AuthService::roleHasCapability('readonly', 'edit'));
        self::assertFalse(AuthService::roleHasCapability('readonly', 'admin'));
    }

    public function testEditorCanViewAndEditButNotAdmin(): void
    {
        self::assertTrue(AuthService::roleHasCapability('editor', 'view'));
        self::assertTrue(AuthService::roleHasCapability('editor', 'edit'));
        self::assertFalse(AuthService::roleHasCapability('editor', 'admin'));
    }

    public function testAdminCanDoEverything(): void
    {
        self::assertTrue(AuthService::roleHasCapability('admin', 'view'));
        self::assertTrue(AuthService::roleHasCapability('admin', 'edit'));
        self::assertTrue(AuthService::roleHasCapability('admin', 'admin'));
    }

    public function testUnknownRoleOrCapabilityDenied(): void
    {
        self::assertFalse(AuthService::roleHasCapability('ghost', 'view'));
        self::assertFalse(AuthService::roleHasCapability('admin', 'nonsense'));
    }

    public function testRoleValidation(): void
    {
        self::assertTrue(AuthService::isValidRole('admin'));
        self::assertTrue(AuthService::isValidRole('editor'));
        self::assertTrue(AuthService::isValidRole('readonly'));
        self::assertFalse(AuthService::isValidRole('superuser'));
    }
}
