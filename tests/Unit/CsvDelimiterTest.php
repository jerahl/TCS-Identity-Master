<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\Csv;
use PHPUnit\Framework\TestCase;

final class CsvDelimiterTest extends TestCase
{
    public function testDetectsCommaTabSemicolonPipe(): void
    {
        self::assertSame(',', Csv::detectDelimiter('a,b,c,d'));
        self::assertSame("\t", Csv::detectDelimiter("a\tb\tc"));
        self::assertSame(';', Csv::detectDelimiter('a;b;c'));
        self::assertSame('|', Csv::detectDelimiter('a|b|c'));
    }

    public function testDefaultsToCommaWhenAmbiguous(): void
    {
        self::assertSame(',', Csv::detectDelimiter('singlecolumn'));
    }

    public function testStripsBomBeforeDetecting(): void
    {
        self::assertSame("\t", Csv::detectDelimiter("\xEF\xBB\xBFEmployeeID\tFirstName\tLastName"));
        self::assertSame('EmployeeID', Csv::stripBom("\xEF\xBB\xBFEmployeeID"));
    }

    public function testReadParsesTabDelimitedWithBom(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'idm_csv');
        file_put_contents($tmp, "\xEF\xBB\xBFuniqueId\tusername\n88\tjdoe\n");
        $rows = Csv::read($tmp);
        unlink($tmp);

        self::assertCount(1, $rows);
        self::assertSame('88', $rows[0]['uniqueId']);
        self::assertSame('jdoe', $rows[0]['username']);
    }

    public function testSplitLinesHandlesCrCrlfAndLf(): void
    {
        // Bare CR (classic Mac / some PowerSchool exports).
        self::assertSame(['a', 'b', 'c'], Csv::splitLines("a\rb\rc"));
        // CRLF (Windows).
        self::assertSame(['a', 'b', 'c'], Csv::splitLines("a\r\nb\r\nc"));
        // LF (Unix) with a trailing newline dropped.
        self::assertSame(['a', 'b'], Csv::splitLines("a\nb\n"));
        // Strips a leading BOM off the first line.
        self::assertSame(['h1,h2'], Csv::splitLines("\xEF\xBB\xBFh1,h2"));
    }

    public function testReadParsesBareCrLineEndings(): void
    {
        // PowerSchool-style export with bare CR endings — the regression that
        // produced "rows 0": fgetcsv read the whole file as one line.
        $tmp = tempnam(sys_get_temp_dir(), 'idm_csv');
        file_put_contents($tmp, "USERS.dcid,USERS.First_Name\r1001,Jennifer\r1002,Brandon\r");
        $rows = Csv::read($tmp);
        unlink($tmp);

        self::assertCount(2, $rows);
        self::assertSame('1001', $rows[0]['USERS.dcid']);
        self::assertSame('Brandon', $rows[1]['USERS.First_Name']);
    }
}
