<?php

declare(strict_types=1);

/**
 * Import a NextGen HR export into staging + the golden record.
 *   php bin/import_nextgen.php --file=/var/idm/feeds/nextgen/staff.csv [--dry-run]
 * With no --file, uses the newest CSV in FEED_NEXTGEN_DIR.
 */

use App\Import\Cli;

require __DIR__ . '/../src/bootstrap.php';

exit(Cli::main('nextgen', $_SERVER['argv'] ?? []));
