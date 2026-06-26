<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config;
use App\Import\Importer;
use App\Import\ImportSource;
use App\Sync\Sftp\PhpseclibSftpClient;
use App\Sync\Sftp\SftpClient;
use RuntimeException;

/**
 * Orchestrates pulling feed CSVs from SFTP and importing them. Shared by the CLI
 * (cron) and the web "Pull from SFTP" action. A source is enabled when its
 * SFTP_<SOURCE>_DIR is configured; files land in FEED_<SOURCE>_DIR and are
 * de-duplicated via feed_fetch_log.
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

            $entry = ['key' => $key, 'downloaded' => 0, 'imported' => 0, 'errors' => 0, 'files' => []];
            foreach ($files as $f) {
                $totals['downloaded'] += $f['downloaded'] ? 1 : 0;
                $entry['downloaded'] += $f['downloaded'] ? 1 : 0;
                $row = ['name' => $f['name'], 'imported' => false, 'reason' => $dryRun ? 'dry-run' : ''];

                if (!$dryRun) {
                    $logId = $this->log->record($key, $f['name'], $f['local'], $f['size'], $f['mtime']);
                    if ($doImport) {
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
            $sources[] = $entry;
        }

        return ['dry_run' => $dryRun, 'imported_enabled' => $doImport, 'sources' => $sources, 'totals' => $totals];
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
