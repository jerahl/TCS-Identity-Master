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
        $map = ColumnMap::for('nextgen');
        $raw = [
            'EmployeeID' => '15241', 'FirstName' => 'Jennifer', 'LastName' => 'Marsh',
            'DOB' => '04/12/1986', 'Ethnicity' => 'White', 'HomeSchoolCode' => '401',
            'PersonType' => 'Faculty', 'Primary' => 'Y',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertSame('15241', $row->sourceKey);
        self::assertSame('1986-04-12', $row->dob, 'm/d/Y should parse to Y-m-d');
        self::assertSame(1, $row->schoolId);
        self::assertSame('5', $row->ethnicityCode);
        self::assertSame('faculty', $row->personType);
        self::assertTrue($row->isPrimary);
        self::assertSame([], $row->warnings);
    }

    public function testUnmappedValuesProduceWarningsNotFailures(): void
    {
        $map = ColumnMap::for('nextgen');
        $raw = [
            'EmployeeID' => '999', 'FirstName' => 'Dana', 'LastName' => 'Reed',
            'Ethnicity' => 'Caucasian', 'HomeSchoolCode' => '999', 'Primary' => 'N',
        ];
        $row = $this->normalizer()->normalize($raw, 'nextgen', $map);

        self::assertNull($row->schoolId);
        self::assertNull($row->ethnicityCode);
        self::assertSame('Caucasian', $row->ethnicitySource, 'raw value preserved when unmapped');
        self::assertCount(2, $row->warnings);
        self::assertFalse($row->isPrimary);
    }

    public function testDateParsingVariants(): void
    {
        self::assertSame('1990-05-05', Normalizer::parseDate('1990-05-05'));
        self::assertSame('1990-05-05', Normalizer::parseDate('5/5/1990'));
        self::assertNull(Normalizer::parseDate(''));
        self::assertNull(Normalizer::parseDate(null));
    }
}
