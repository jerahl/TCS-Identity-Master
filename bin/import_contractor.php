<?php

declare(strict_types=1);

/**
 * Import a contractor roster CSV into staging + the golden record.
 *   php bin/import_contractor.php --file=/path/to/contractor.csv [--dry-run]
 * With no --file, uses the newest CSV in FEED_CONTRACTOR_DIR.
 */

use App\Import\Cli;

require __DIR__ . '/../src/bootstrap.php';

exit(Cli::main('contractor', $_SERVER['argv'] ?? []));
