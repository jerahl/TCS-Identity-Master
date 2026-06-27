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
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Cannot open CSV: {$path}");
        }

        $lines = self::splitLines($content);
        if ($lines === []) {
            return [];
        }
        $delim = self::detectDelimiter($lines[0]);
        $header = array_map(static fn($h) => trim((string) $h), str_getcsv(self::stripBom($lines[0]), $delim, '"', '\\'));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cols = str_getcsv($line, $delim, '"', '\\');
            if ($cols === [null] || (count($cols) === 1 && trim((string) ($cols[0] ?? '')) === '')) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Split raw file content into lines, robust to any line ending. PowerSchool
     * and some Windows exports use bare CR or CRLF; PHP 8.1 removed
     * auto_detect_line_endings, so fgets/fgetcsv would read a CR-only file as one
     * giant line (header parses, zero data rows). We normalize here instead.
     * Strips a leading BOM and drops trailing blank lines.
     *
     * @return string[]
     */
    public static function splitLines(string $content): array
    {
        $content = self::stripBom($content);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        // Drop trailing empty lines (e.g. a final newline) without touching
        // blank lines in the middle, which the callers skip on their own.
        while ($lines !== [] && trim((string) end($lines)) === '') {
            array_pop($lines);
        }
        return $lines;
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
