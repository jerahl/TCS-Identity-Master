<?php

declare(strict_types=1);

/**
 * Import a sub roster CSV into staging + the golden record.
 *   php bin/import_sub.php --file=/path/to/sub.csv [--dry-run]
 * With no --file, uses the newest CSV in FEED_SUB_DIR.
 */

use App\Import\Cli;

require __DIR__ . '/../src/bootstrap.php';

exit(Cli::main('sub', $_SERVER['argv'] ?? []));
