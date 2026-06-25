<?php

declare(strict_types=1);

/**
 * Import a PowerSchool staff extract into staging + the golden record.
 *   php bin/import_powerschool.php --file=/var/idm/feeds/powerschool/staff.csv [--dry-run]
 * With no --file, uses the newest CSV in FEED_POWERSCHOOL_DIR.
 */

use App\Import\Cli;

require __DIR__ . '/../src/bootstrap.php';

exit(Cli::main('powerschool', $_SERVER['argv'] ?? []));
