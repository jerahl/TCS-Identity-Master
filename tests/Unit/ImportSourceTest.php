<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Import\ColumnMap;
use App\Import\ImportSource;
use App\Import\Normalizer;
use App\Matching\InMemoryMatchLookup;
use App\Matching\Matcher;
use App\Matching\MatchDecision;
use PHPUnit\Framework\TestCase;

final class ImportSourceTest extends TestCase
{
    public function testNewCategoriesExistWithCorrectTypeAndCrosswalk(): void
    {
        $intern = ImportSource::for('intern');
        self::assertSame('intern', $intern->personType);
        self::assertSame('intern_csv', $intern->crosswalkSystem);
        self::assertSame('intern', $intern->batchSystem);

        self::assertSame('sub', ImportSource::for('sub')->personType);
        self::assertSame('sub', ImportSource::for('sub')->crosswalkSystem);
        self::assertSame('contractor', ImportSource::for('contractor')->personType);
        self::assertSame('contractor', ImportSource::for('contractor')->crosswalkSystem);
    }

    public function testKeysAndRoundTrip(): void
    {
        self::assertSame(['nextgen', 'powerschool', 'intern', 'sub', 'contractor'], ImportSource::keys());
        self::assertTrue(ImportSource::exists('intern'));
        self::assertFalse(ImportSource::exists('teacher'));
        self::assertSame('intern', ImportSource::fromBatchSystem('intern')?->key);
        self::assertSame('powerschool', ImportSource::fromBatchSystem('powerschool')?->key);
        self::assertNull(ImportSource::fromBatchSystem('manual'));
    }

    public function testEveryColumnMapResolves(): void
    {
        foreach (ImportSource::all() as $s) {
            self::assertArrayHasKey('source_key', ColumnMap::for($s->columnMapKey));
        }
    }

    public function testInternRowNormalizesToInternTypeAndCrosswalk(): void
    {
        // alias group 'powerschool' resolves the SchoolID; type forced to intern.
        $norm = new Normalizer(['powerschool' => ['2100' => 7]], ['asian' => '2']);
        $source = ImportSource::for('intern');
        $raw = ['InternID' => '90', 'FirstName' => 'Maya', 'LastName' => 'Patel', 'DOB' => '2002-03-15', 'Ethnicity' => 'Asian', 'SchoolID' => '2100', 'Primary' => 'Y'];

        $row = $norm->normalize($raw, $source->batchSystem, ColumnMap::for($source->columnMapKey), $source->crosswalkSystem, $source->aliasSystem, $source->personType);

        self::assertSame('intern', $row->system);
        self::assertSame('intern_csv', $row->sourceSystem());
        self::assertSame('intern', $row->personType);
        self::assertSame(7, $row->schoolId);
        self::assertSame('90', $row->sourceKey);
        self::assertNull($row->employeeId, 'intern feed has no employee id');
    }

    public function testInternMatchesExistingInternByCrosswalkSourceId(): void
    {
        // A re-import of an intern already linked via intern_csv:88 auto-matches.
        $lk = new InMemoryMatchLookup();
        $lk->addPerson(3, 'Elena', 'Ruiz', null);
        $lk->addSourceId('intern_csv', '88', 3);

        $row = (new Normalizer([], []))->normalize(
            ['InternID' => '88', 'FirstName' => 'Elena', 'LastName' => 'Ruiz'],
            'intern',
            ColumnMap::for('intern'),
            'intern_csv',
            'powerschool',
            'intern'
        );
        $d = (new Matcher(90))->match($row, $lk);
        self::assertSame(MatchDecision::AUTO, $d->action);
        self::assertSame(3, $d->personId);
        self::assertSame('source_id', $d->basis);
    }
}
