<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config;
use App\Import\Csv;
use App\Import\Importer;
use App\Import\ImportSource;
use App\Import\PowerSchoolBundle;
use App\Import\PowerSchoolImporter;
use App\Sync\Sftp\PhpseclibSftpClient;
use App\Sync\Sftp\SftpClient;
use RuntimeException;

/**
 * Orchestrates pulling feed CSVs from SFTP and importing them. Shared by the CLI
 * (cron) and the web "Pull from SFTP" action. A source is enabled when its
 * SFTP_<SOURCE>_DIR is configured; files land in FEED_<SOURCE>_DIR and are
 * de-duplicated via feed_fetch_log.
 *
 * PowerSchool is no longer pulled over SFTP: it now reads directly from
 * PowerSchool's Oracle DB via ODBC (importPowerSchoolOdbc(), gated on
 * PS_ODBC_DSN). The legacy SFTP three-file path below is kept only as a fallback
 * for sites still dropping CSVs in SFTP_POWERSCHOOL_DIR.
 */
final class FeedSync
{
    private SftpClient $client;
    private FeedFetcher $fetcher;
    private FetchLog $log;

    public function __construct(SftpClient $client, ?FetchLog $log = null)
    {
        $this->client = $client;
        $this->fetcher = new FeedFetcher($client);
        $this->log = $log ?? new FetchLog();
    }

    /** Build from environment config (phpseclib client). */
    public static function fromConfig(): self
    {
        return new self(self::clientFromConfig());
    }

    /** Build a phpseclib SFTP client from the SFTP_* environment config. */
    public static function clientFromConfig(): PhpseclibSftpClient
    {
        $keyFile = Config::get('SFTP_PRIVATE_KEY_FILE');
        $privateKey = ($keyFile !== null && is_file($keyFile) && is_readable($keyFile))
            ? (string) file_get_contents($keyFile) : null;

        return new PhpseclibSftpClient(
            host: (string) Config::get('SFTP_HOST', ''),
            port: (int) Config::get('SFTP_PORT', '22'),
            user: (string) Config::get('SFTP_USER', ''),
            password: Config::get('SFTP_PASS'),
            privateKey: $privateKey,
            passphrase: Config::get('SFTP_PASSPHRASE'),
            fingerprint: Config::get('SFTP_FINGERPRINT'),
        );
    }

    /** True when PowerSchool's direct Oracle ODBC connection is configured. */
    public static function powerSchoolOdbcEnabled(): bool
    {
        return trim((string) Config::get('PS_ODBC_DSN', '')) !== '';
    }

    /**
     * Run the PowerSchool import directly from Oracle over ODBC (no SFTP / no
     * CSV). Returns a source-result entry shaped like the entries in run(), or
     * null when PS_ODBC_DSN isn't configured.
     *
     * @return array<string,mixed>|null
     */
    public static function importPowerSchoolOdbc(bool $dryRun = false, ?string $actor = null): ?array
    {
        if (!self::powerSchoolOdbcEnabled()) {
            return null;
        }
        $entry = ['key' => 'powerschool', 'source' => 'oracle-odbc', 'downloaded' => 0, 'imported' => 0, 'errors' => 0, 'files' => []];
        if ($dryRun) {
            $entry['files'][] = ['name' => '(oracle odbc)', 'imported' => false, 'reason' => 'dry-run — would query USERS + TEACHERS + SCHOOLSTAFF'];
            return $entry;
        }
        try {
            $res = (new PowerSchoolImporter())->runFromOdbc(false, $actor);
            $c = $res['counts'];
            $entry['imported'] = 1;
            $entry['files'][] = ['name' => '(oracle odbc PowerSchool import)', 'imported' => true,
                'reason' => "batch #{$res['batch_id']} · auto {$c['auto_match']} · new {$c['new']} · review {$c['needs_review']} · assignments {$c['assignments']}"];
        } catch (\Throwable $e) {
            $entry['errors'] = 1;
            $entry['files'][] = ['name' => '(oracle odbc PowerSchool import)', 'imported' => false, 'reason' => 'import failed: ' . $e->getMessage()];
        }
        return $entry;
    }

    /** Import-source keys that have an SFTP remote directory configured. */
    public static function configuredSources(): array
    {
        $out = [];
        foreach (ImportSource::keys() as $key) {
            if (trim((string) Config::get('SFTP_' . strtoupper($key) . '_DIR', '')) !== '') {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Fetch (and optionally import) new files for the given sources.
     *
     * @param string[] $sourceKeys
     * @return array<string,mixed> summary
     */
    public function run(array $sourceKeys, bool $dryRun = false, bool $doImport = true, ?string $actor = null): array
    {
        $actor ??= 'system:fetch_feeds';
        $this->client->connect();

        $sources = [];
        $totals = ['downloaded' => 0, 'imported' => 0, 'errors' => 0];

        foreach ($sourceKeys as $key) {
            if (!ImportSource::exists($key)) {
                continue;
            }
            $remoteDir = trim((string) Config::get('SFTP_' . strtoupper($key) . '_DIR', ''));
            if ($remoteDir === '') {
                continue;
            }
            $localDir = trim((string) Config::get('FEED_' . strtoupper($key) . '_DIR', ''));
            if ($localDir === '') {
                $sources[] = ['key' => $key, 'error' => "FEED_" . strtoupper($key) . "_DIR not set", 'files' => []];
                $totals['errors']++;
                continue;
            }
            $pattern = (string) Config::get('SFTP_' . strtoupper($key) . '_PATTERN', '*.csv');

            try {
                $files = $this->fetcher->fetchSource($remoteDir, $pattern, $localDir, $this->log->fetchedMtimes($key), $dryRun);
            } catch (\Throwable $e) {
                $sources[] = ['key' => $key, 'error' => $e->getMessage(), 'files' => []];
                $totals['errors']++;
                continue;
            }

            // PowerSchool is three joined files — download all, import once.
            $isPowerSchool = $key === 'powerschool';

            $entry = ['key' => $key, 'downloaded' => 0, 'imported' => 0, 'errors' => 0, 'files' => []];
            $psLogIds = [];
            foreach ($files as $f) {
                $totals['downloaded'] += $f['downloaded'] ? 1 : 0;
                $entry['downloaded'] += $f['downloaded'] ? 1 : 0;
                $row = ['name' => $f['name'], 'imported' => false, 'reason' => $dryRun ? 'dry-run' : ''];

                if (!$dryRun) {
                    $logId = $this->log->record($key, $f['name'], $f['local'], $f['size'], $f['mtime']);
                    if ($isPowerSchool) {
                        $psLogIds[] = $logId;            // combined import after the loop
                    } elseif ($doImport) {
                        try {
                            $res = (new Importer())->run($key, $f['local'], null, false, $actor, $f['name']);
                            $this->log->markImported($logId, $res['batch_id']);
                            $row['imported'] = true;
                            $row['reason'] = 'batch #' . $res['batch_id'] . ' · ' . self::countsLine($res['counts']);
                            $entry['imported']++;
                            $totals['imported']++;
                        } catch (\Throwable $e) {
                            $this->log->markFailed($logId, $e->getMessage());
                            $row['reason'] = 'import failed: ' . $e->getMessage();
                            $entry['errors']++;
                            $totals['errors']++;
                        }
                    }
                }
                $entry['files'][] = $row;
            }

            if ($isPowerSchool && $doImport && !$dryRun) {
                $this->importPowerSchool($localDir, $actor, $psLogIds, $entry, $totals);
            }

            $sources[] = $entry;
        }

        return ['dry_run' => $dryRun, 'imported_enabled' => $doImport, 'sources' => $sources, 'totals' => $totals];
    }

    /**
     * Combined PowerSchool import: join the current USERS + TEACHERS + SCHOOLSTAFF
     * files in $localDir (classified by header, so partial updates still pick up
     * the other two) and run them through PowerSchoolImporter once.
     *
     * @param int[] $logIds feed_fetch_log rows for the files downloaded this run
     * @param array<string,mixed> $entry  @param array<string,int> $totals
     */
    private function importPowerSchool(string $localDir, string $actor, array $logIds, array &$entry, array &$totals): void
    {
        if ($logIds === []) {
            return; // nothing new downloaded
        }
        $found = ['users' => null, 'teachers' => null, 'schoolstaff' => null];
        foreach (glob(rtrim($localDir, '/') . '/*.csv') ?: [] as $f) {
            $rows = Csv::read($f);
            if ($rows === []) {
                continue;
            }
            $kind = PowerSchoolBundle::classify($rows[0]);
            if ($kind !== null && $found[$kind] === null) {
                $found[$kind] = $f;
            }
        }
        $missing = array_keys(array_filter($found, static fn($v) => $v === null));
        if ($missing !== []) {
            $reason = 'waiting for all 3 PowerSchool files; missing: ' . implode(', ', $missing);
            foreach ($logIds as $id) {
                $this->log->markFailed($id, $reason);
            }
            $entry['errors']++;
            $totals['errors']++;
            $entry['files'][] = ['name' => '(combined)', 'imported' => false, 'reason' => $reason];
            return;
        }
        try {
            $res = (new PowerSchoolImporter())->run($found['users'], $found['teachers'], $found['schoolstaff'], false, $actor);
            foreach ($logIds as $id) {
                $this->log->markImported($id, $res['batch_id']);
            }
            $entry['imported'] += count($logIds);
            $totals['imported'] += count($logIds);
            $c = $res['counts'];
            $entry['files'][] = ['name' => '(combined PowerSchool import)', 'imported' => true,
                'reason' => "batch #{$res['batch_id']} · auto {$c['auto_match']} · new {$c['new']} · review {$c['needs_review']} · assignments {$c['assignments']}"];
        } catch (\Throwable $e) {
            foreach ($logIds as $id) {
                $this->log->markFailed($id, $e->getMessage());
            }
            $entry['errors']++;
            $totals['errors']++;
            $entry['files'][] = ['name' => '(combined PowerSchool import)', 'imported' => false, 'reason' => 'import failed: ' . $e->getMessage()];
        }
    }

    private function connect(): void
    {
        $this->reflectConnect();
    }

    /** Connect the underlying client (FeedFetcher holds it privately). */
    private function reflectConnect(): void
    {
        // FeedFetcher wraps the client; expose connect via a tiny accessor.
        $this->fetcher->connect();
    }

    private static function countsLine(array $c): string
    {
        return "auto {$c['auto_match']} · new {$c['new']} · review {$c['needs_review']} · err {$c['errors']}";
    }
}
