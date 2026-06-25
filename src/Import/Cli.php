<?php

declare(strict_types=1);

namespace App\Import;

use App\Config;

/**
 * Shared CLI for the feed importers. Resolves the input file (from --file or the
 * configured feed directory), runs the Importer, and prints a summary.
 *
 *   php bin/import_nextgen.php --file=/path/to/nextgen.csv [--dry-run]
 *   php bin/import_powerschool.php --dry-run        # newest file in FEED dir
 */
final class Cli
{
    public static function main(string $system, array $argv): int
    {
        $opts = self::parse($argv);
        $dryRun = isset($opts['dry-run']);

        $file = $opts['file'] ?? self::newestInFeedDir($system);
        if ($file === null) {
            fwrite(STDERR, "No --file given and no CSV found in the configured feed directory for {$system}.\n");
            return 1;
        }

        echo "Importing {$system} feed: {$file}" . ($dryRun ? "  (DRY RUN — no writes)\n" : "\n");

        try {
            $result = (new Importer())->run($system, $file, null, $dryRun);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Import failed: ' . $e->getMessage() . "\n");
            return 1;
        }

        self::printOutcomes($result['outcomes']);
        $c = $result['counts'];
        echo "\n";
        echo "  rows {$c['total']}  ·  auto {$c['auto_match']}  ·  new {$c['new']}  ·  review {$c['needs_review']}"
            . "  ·  skipped {$c['skipped']}  ·  errors {$c['errors']}  ·  unmapped {$c['unmapped']}\n";
        if (!$dryRun) {
            echo "  batch #{$result['batch_id']} recorded.\n";
        } else {
            echo "  (dry run — nothing written; same-file rows that would create a person still show as 'new')\n";
        }
        return $c['errors'] > 0 && ($c['auto_match'] + $c['new'] + $c['needs_review']) === 0 ? 1 : 0;
    }

    /** @param array<int,array<string,mixed>> $outcomes */
    private static function printOutcomes(array $outcomes): void
    {
        $label = [
            'auto_match' => 'AUTO  ', 'new' => 'NEW   ', 'needs_review' => 'REVIEW',
            'skipped' => 'SKIP  ', 'error' => 'ERROR ',
        ];
        foreach ($outcomes as $o) {
            $tag = $label[$o['action']] ?? $o['action'];
            printf("  [%s] %-26s %s\n", $tag, mb_substr($o['name'], 0, 26), $o['reason']);
            foreach ($o['warnings'] as $w) {
                printf("           ! %s\n", $w);
            }
        }
    }

    /** @return array<string,string> */
    private static function parse(array $argv): array
    {
        $opts = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (str_starts_with($arg, '--')) {
                $kv = explode('=', substr($arg, 2), 2);
                $opts[$kv[0]] = $kv[1] ?? '1';
            }
        }
        return $opts;
    }

    private static function newestInFeedDir(string $system): ?string
    {
        $dirKey = $system === 'nextgen' ? 'FEED_NEXTGEN_DIR' : 'FEED_POWERSCHOOL_DIR';
        $dir = Config::get($dirKey);
        if ($dir === null || !is_dir($dir)) {
            return null;
        }
        $files = glob(rtrim($dir, '/') . '/*.csv') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }
}
