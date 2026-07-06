<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\ApiController;
use PHPUnit\Framework\TestCase;

/**
 * The password endpoint's debug-log redaction: password values must never
 * survive into the logged body, whatever shape the request takes.
 */
final class ApiRedactionTest extends TestCase
{
    public function testRedactsSingleEvent(): void
    {
        $out = ApiController::redactSecrets('{"uniqueId":"8f3c","password":"Falcon-42"}');
        self::assertStringNotContainsString('Falcon-42', $out);
        self::assertStringContainsString('"uniqueId":"8f3c"', $out);
        self::assertStringContainsString('[redacted]', $out);
    }

    public function testRedactsBatchAndVariantKeys(): void
    {
        $out = ApiController::redactSecrets(
            '[{"uniqueId":"a","password":"one"},{"uniqueId":"b","tempPassword":"two"}]'
        );
        self::assertStringNotContainsString('one', $out);
        self::assertStringNotContainsString('two', $out);
        self::assertStringContainsString('"uniqueId":"a"', $out);
        self::assertStringContainsString('"uniqueId":"b"', $out);
    }

    public function testWithholdsUnparseableBody(): void
    {
        $out = ApiController::redactSecrets('{"password":"leaky');
        self::assertStringNotContainsString('leaky', $out);
        self::assertStringContainsString('withheld', $out);
    }
}
