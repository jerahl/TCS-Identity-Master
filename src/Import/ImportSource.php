<?php

declare(strict_types=1);

namespace App\Import;

use InvalidArgumentException;

/**
 * An import source category. Decouples the feed the operator picks from the
 * several DB columns it drives:
 *   - batchSystem     — import_batch/staging_record/assignment.source/source_of_record
 *   - crosswalkSystem — person_source_id.system (provenance + matcher lookups)
 *   - aliasSystem     — which school_code_alias group resolves school codes
 *   - personType      — type stamped on people created from this feed (null = trust the feed)
 *   - columnMapKey    — the CSV header map
 *
 * Interns / long-term subs / contractors are first-class sources so their people
 * get the right type and their source IDs keep distinct provenance.
 */
final class ImportSource
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $batchSystem,
        public readonly string $crosswalkSystem,
        public readonly string $aliasSystem,
        public readonly ?string $personType,
        public readonly string $columnMapKey,
        public readonly bool $headerless = false,
    ) {
    }

    /** @var array<string,array<string,mixed>> */
    private const DEFS = [
        'nextgen'     => ['label' => 'NextGen (HR)',          'batch' => 'nextgen',     'crosswalk' => 'nextgen',     'alias' => 'nextgen',     'type' => null,        'map' => 'nextgen',     'headerless' => false],
        'powerschool' => ['label' => 'PowerSchool',           'batch' => 'powerschool', 'crosswalk' => 'powerschool', 'alias' => 'powerschool', 'type' => null,        'map' => 'powerschool', 'headerless' => true],
        'intern'      => ['label' => 'Intern',                'batch' => 'intern',      'crosswalk' => 'intern_csv',  'alias' => 'powerschool', 'type' => 'intern',     'map' => 'intern',      'headerless' => false],
        'sub'         => ['label' => 'Long-term substitute',  'batch' => 'sub',         'crosswalk' => 'sub',         'alias' => 'powerschool', 'type' => 'sub',        'map' => 'sub',         'headerless' => false],
        'contractor'  => ['label' => 'Contract employee',     'batch' => 'contractor',  'crosswalk' => 'contractor',  'alias' => 'powerschool', 'type' => 'contractor', 'map' => 'contractor',  'headerless' => false],
    ];

    public static function for(string $key): self
    {
        if (!isset(self::DEFS[$key])) {
            throw new InvalidArgumentException("Unknown import source: {$key}");
        }
        $d = self::DEFS[$key];
        return new self($key, $d['label'], $d['batch'], $d['crosswalk'], $d['alias'], $d['type'], $d['map'], (bool) $d['headerless']);
    }

    public static function exists(string $key): bool
    {
        return isset(self::DEFS[$key]);
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::DEFS);
    }

    /** @return self[] */
    public static function all(): array
    {
        return array_map(static fn(string $k) => self::for($k), self::keys());
    }

    /** Resolve the source whose batchSystem matches (for rebuilding staged rows). */
    public static function fromBatchSystem(string $batchSystem): ?self
    {
        foreach (self::keys() as $k) {
            $s = self::for($k);
            if ($s->batchSystem === $batchSystem) {
                return $s;
            }
        }
        return null;
    }
}
