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

        $firstLine = fgets($fh);
        if ($firstLine === false) {
            fclose($fh);
            return [];
        }
        $delim = self::detectDelimiter($firstLine);
        $header = array_map(static fn($h) => trim((string) $h), str_getcsv(self::stripBom($firstLine), $delim, '"', '\\'));

        $rows = [];
        while (($cols = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
            if ($cols === [null] || (count($cols) === 1 && trim((string) ($cols[0] ?? '')) === '')) {
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

    /**
     * Guess the field delimiter from a header line: whichever of comma / tab /
     * semicolon / pipe appears most. Defaults to comma. (PowerSchool and some
     * NextGen exports are tab-delimited.)
     */
    public static function detectDelimiter(string $line): string
    {
        $line = self::stripBom($line);
        $best = ',';
        $bestCount = 0;
        foreach ([',', "\t", ';', '|'] as $d) {
            $n = substr_count($line, $d);
            if ($n > $bestCount) {
                $bestCount = $n;
                $best = $d;
            }
        }
        return $best;
    }

    /** Strip a leading UTF-8 BOM. */
    public static function stripBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }
}
