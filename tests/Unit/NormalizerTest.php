<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\ColumnMap;
use App\Import\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase
{
    private function normalizer(): Normalizer
    {
        // school_code_alias: nextgen 401 -> school 1; ethnicity White -> 5.
        return new Normalizer(
            ['nextgen' => ['401' => 1], 'powerschool' => ['4010' => 1]],
            ['white' => '5', 'black or african american' => '3'],
        );
    }

    public function testResolvesSchoolAndEthnicity(): void
    {
        // Real NextGen ITExtract.csv headers.
        $map = ColumnMap::for('nextgen');
        $raw = [
            'Employee Number' => '15241', 'First Name' => 'Jennifer', 'Last Name' => 'Marsh',
            'Hire Date' => '08/18/2014', 'Ethnicity Description' => 'White', 'Location Code' => '401',
            'Gender Type' => 'Female', 'Job Code Desc' => 'Teacher',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertSame('15241', $row->sourceKey);
        self::assertSame('15241', $row->employeeId);
        self::assertSame('Jennifer', $row->firstName);
        self::assertSame('Marsh', $row->lastName);
        self::assertSame('2014-08-18', $row->hireDate, 'm/d/Y should parse to Y-m-d');
        self::assertSame(1, $row->schoolId);
        self::assertSame('5', $row->ethnicityCode);
        self::assertTrue($row->isPrimary, 'defaults to primary when the feed has no Primary column');
        self::assertSame([], $row->warnings);
    }

    public function testUnmappedValuesProduceWarningsNotFailures(): void
    {
        $map = ColumnMap::for('nextgen');
        $raw = [
            'Employee Number' => '999', 'First Name' => 'Dana', 'Last Name' => 'Reed',
            'Ethnicity Description' => 'Caucasian', 'Location Code' => '999',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertNull($row->schoolId);
        self::assertNull($row->ethnicityCode);
        self::assertSame('Caucasian', $row->ethnicitySource, 'raw value preserved when unmapped');
        self::assertCount(2, $row->warnings);
    }

    public function testDateParsingVariants(): void
    {
        self::assertSame('1990-05-05', Normalizer::parseDate('1990-05-05'));
        self::assertSame('1990-05-05', Normalizer::parseDate('5/5/1990'));
        self::assertNull(Normalizer::parseDate(''));
        self::assertNull(Normalizer::parseDate(null));
    }
}
