<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * The OneSync API token check — constant-time, and fails closed when no key is
 * configured (so the API is never accidentally open).
 */
final class ApiAuthTest extends TestCase
{
    public function testMatchesExactToken(): void
    {
        self::assertTrue(ApiController::tokenMatches('s3cret', 's3cret'));
    }

    public function testRejectsWrongToken(): void
    {
        self::assertFalse(ApiController::tokenMatches('nope', 's3cret'));
    }

    public function testFailsClosedWhenNoKeyConfigured(): void
    {
        self::assertFalse(ApiController::tokenMatches('anything', ''));
        self::assertFalse(ApiController::tokenMatches('anything', null));
    }

    public function testRejectsMissingToken(): void
    {
        self::assertFalse(ApiController::tokenMatches(null, 's3cret'));
        self::assertFalse(ApiController::tokenMatches('', 's3cret'));
    }
}
