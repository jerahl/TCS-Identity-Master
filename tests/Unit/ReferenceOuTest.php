<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ReferenceService;
use PHPUnit\Framework\TestCase;

/**
 * OU-mapping normalization for the editable school map: Google OU paths are
 * stored in Google's leading-slash form (district convention
 * /tcs/faculty/{school OU}), AD OUs pass through trimmed, and blank always
 * stores NULL — "unmapped" is one state, never '' vs NULL ambiguity.
 */
final class ReferenceOuTest extends TestCase
{
    public function testGoogleOuGetsLeadingSlash(): void
    {
        self::assertSame('/tcs/faculty/NHS', ReferenceService::normalizeGoogleOu('tcs/faculty/NHS'));
        self::assertSame('/tcs/faculty/NHS', ReferenceService::normalizeGoogleOu('/tcs/faculty/NHS'));
        self::assertSame('/tcs/faculty/NHS', ReferenceService::normalizeGoogleOu('  /tcs/faculty/NHS/  '));
    }

    public function testGoogleOuBlankAndRoot(): void
    {
        self::assertNull(ReferenceService::normalizeGoogleOu(''));
        self::assertNull(ReferenceService::normalizeGoogleOu('   '));
        self::assertNull(ReferenceService::normalizeGoogleOu(null));
        self::assertSame('/', ReferenceService::normalizeGoogleOu('/'));
    }

    public function testAdOuTrimsAndNullsBlank(): void
    {
        self::assertSame('OU=NHS', ReferenceService::cleanOu('  OU=NHS  '));
        self::assertSame('OU=STC,OU=CO', ReferenceService::cleanOu('OU=STC,OU=CO'));
        self::assertNull(ReferenceService::cleanOu(''));
        self::assertNull(ReferenceService::cleanOu(null));
    }
}
