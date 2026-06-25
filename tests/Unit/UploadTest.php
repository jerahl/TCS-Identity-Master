<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Upload;
use PHPUnit\Framework\TestCase;

final class UploadTest extends TestCase
{
    public function testAcceptsValidCsv(): void
    {
        self::assertNull(Upload::validateMeta(UPLOAD_ERR_OK, 'nextgen_staff.csv', 1024, 20 * 1048576));
    }

    public function testRejectsNonCsv(): void
    {
        self::assertSame('Only .csv files are accepted.', Upload::validateMeta(UPLOAD_ERR_OK, 'data.xlsx', 1024, 20 * 1048576));
    }

    public function testRejectsEmptyAndOversize(): void
    {
        self::assertSame('The uploaded file is empty.', Upload::validateMeta(UPLOAD_ERR_OK, 'x.csv', 0, 1000));
        self::assertStringContainsString('too large', Upload::validateMeta(UPLOAD_ERR_OK, 'x.csv', 2_000_000, 1_048_576));
    }

    public function testReportsUploadErrors(): void
    {
        self::assertSame('No file was selected.', Upload::validateMeta(UPLOAD_ERR_NO_FILE, '', 0, 1000));
        self::assertSame('The file exceeds the upload size limit.', Upload::validateMeta(UPLOAD_ERR_INI_SIZE, 'x.csv', 1, 1000));
    }

    public function testSanitizeName(): void
    {
        self::assertSame('staff_2026.csv', Upload::sanitizeName('staff 2026.csv'));
        self::assertSame('evil.csv', Upload::sanitizeName('../../etc/evil.csv'));
        self::assertSame('b_c.csv', Upload::sanitizeName('a/b\\c.csv')); // basename strips a/, backslash -> _
    }
}
