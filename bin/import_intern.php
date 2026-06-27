<?php

declare(strict_types=1);

/**
 * Import a intern roster CSV into staging + the golden record.
 *   php bin/import_intern.php --file=/path/to/intern.csv [--dry-run]
 * With no --file, uses the newest CSV in FEED_INTERN_DIR.
 */

use App\Import\Cli;

require __DIR__ . '/../src/bootstrap.php';

exit(Cli::main('intern', $_SERVER['argv'] ?? []));
