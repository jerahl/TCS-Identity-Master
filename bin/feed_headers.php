<?php

declare(strict_types=1);

/**
 * Inspect a feed CSV: detect its delimiter, list its column headers, and show
 * how they line up with the source's ColumnMap — so you can fix mismatches
 * (wrong delimiter, different/renamed headers) that cause rows to be skipped.
 *
 *   php bin/feed_headers.php --system=nextgen --file=/var/idm/feeds/nextgen/staff.csv
 */

use App\Import\ColumnMap;
use App\Import\Csv;
use App\Import\ImportSource;

require __DIR__ . '/../src/bootstrap.php';

$opts = [];
foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? '1';
    }
}
$system = (string) ($opts['system'] ?? '');
$file = (string) ($opts['file'] ?? '');

if (!ImportSource::exists($system) || $file === '') {
    fwrite(STDERR, "Usage: php bin/feed_headers.php --system=<" . implode('|', ImportSource::keys()) . "> --file=<path>\n");
    exit(1);
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "File not found or unreadable: {$file}\n");
    exit(1);
}

$fh = fopen($file, 'rb');
$firstLine = $fh !== false ? fgets($fh) : false;
$dataLine = $fh !== false ? fgets($fh) : false;
if ($fh !== false) {
    fclose($fh);
}
if ($firstLine === false) {
    fwrite(STDERR, "File is empty.\n");
    exit(1);
}

$delim = Csv::detectDelimiter($firstLine);
$delimName = [',' => 'comma', "\t" => 'TAB', ';' => 'semicolon', '|' => 'pipe'][$delim] ?? $delim;
$source = ImportSource::for($system);

echo "File:       {$file}\n";
echo "Delimiter:  {$delimName}\n";

// Headerless sources (e.g. PowerSchool extract): the first line is data. Show
// each column by position with a sample value so the 0-based indexes can be set.
if ($source->headerless) {
    $cols = str_getcsv(Csv::stripBom($firstLine), $delim, '"', '\\');
    echo "Columns:    " . count($cols) . "  (HEADERLESS — positional)\n\n";
    echo "Column positions (index → first-row value):\n";
    foreach ($cols as $i => $v) {
        printf("  [%2d] %s\n", $i, trim((string) $v));
    }
    echo "\nSet 0-based indexes in src/Import/ColumnMap.php['{$system}'], e.g.\n";
    echo "  'source_key' => 0, 'employee_id' => 1, 'first' => 2, 'last' => 3, ...\n";
    echo "Tell me the index for each field and I'll set the map.\n";
    exit(0);
}

$headers = array_map('trim', str_getcsv(Csv::stripBom($firstLine), $delim, '"', '\\'));
echo "Columns:    " . count($headers) . "\n";
if (count($headers) <= 1) {
    echo "\n⚠  Only one column detected — the delimiter is probably wrong, or the file\n";
    echo "   isn't really CSV. Header line was:\n   " . trim($firstLine) . "\n";
}
echo "\nHeaders found:\n  - " . implode("\n  - ", $headers) . "\n";

$map = ColumnMap::for(ImportSource::for($system)->columnMapKey);
$present = array_flip($headers);
echo "\nColumnMap for '{$system}' (logical field -> expected header):\n";
$missing = [];
foreach ($map as $logical => $expected) {
    $ok = isset($present[$expected]);
    printf("  %-13s -> %-18s %s\n", $logical, $expected, $ok ? 'OK' : 'MISSING');
    if (!$ok) {
        $missing[] = "{$logical} (expects \"{$expected}\")";
    }
}

$unused = array_values(array_diff($headers, array_values($map)));
if ($unused !== []) {
    echo "\nHeaders in the file NOT used by the map:\n  - " . implode("\n  - ", $unused) . "\n";
}
if ($missing !== []) {
    echo "\n⚠  Unmapped logical fields: " . implode(', ', $missing) . "\n";
    echo "   Update src/Import/ColumnMap.php for '{$system}' to match the real headers above.\n";
} else {
    echo "\nAll mapped fields are present. ✅\n";
}
