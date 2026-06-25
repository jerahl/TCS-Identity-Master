<?php

declare(strict_types=1);

namespace App\Import;

use RuntimeException;

/**
 * Minimal CSV reader: first row is the header; returns a list of associative
 * rows keyed by trimmed header. Blank lines are skipped.
 */
final class Csv
{
    /** @return array<int,array<string,string>> */
    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("CSV not found or unreadable: {$path}");
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $rows = [];
        $header = null;
        while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($cols === [null] || (count($cols) === 1 && trim((string) ($cols[0] ?? '')) === '')) {
                continue;
            }
            if ($header === null) {
                $header = array_map(static fn($h) => trim((string) $h), $cols);
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }
}
