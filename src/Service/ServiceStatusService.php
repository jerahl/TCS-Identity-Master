<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;
use App\Import\OneSyncResultImporter;
use App\Support\Crypto;
use App\Sync\FeedSync;
use PDO;

/**
 * Read-only health snapshot of the moving parts behind Identity Master, for the
 * admin "Services" page: the app database, OneSync's source DB (read), the
 * OneSync DB sync freshness, the username/password write-back API, the SFTP
 * feed config, the PowerSchool ODBC connection, and the VPN monitor.
 *
 * Each entry is a small envelope — key, label, state, one-line detail, and a few
 * key/value facts — so the template renders them uniformly. The app-DB card
 * reuses the app's already-open connection (a `SELECT 1`); the OneSync source
 * probe opens a fresh connection bounded by a short connect timeout so an
 * unreachable OneSync host fails fast instead of wedging the page. Everything
 * else reports configuration presence, not a round-trip, to keep the page cheap.
 *
 * State vocabulary: 'ok' | 'warn' | 'down' | 'disabled'.
 */
final class ServiceStatusService
{
    private DashboardService $dash;
    private VpnMonitorService $vpn;
    private int $pingTimeout;

    public function __construct(?DashboardService $dash = null, ?VpnMonitorService $vpn = null, ?int $pingTimeout = null)
    {
        $this->dash = $dash ?? new DashboardService();
        $this->vpn = $vpn ?? new VpnMonitorService();
        $this->pingTimeout = $pingTimeout ?? max(1, Config::int('SERVICE_PING_TIMEOUT', 3));
    }

    /** @return list<array{key:string,label:string,state:string,detail:string,facts:list<array{0:string,1:string}>}> */
    public function services(): array
    {
        return [
            $this->appDb(),
            $this->onesyncSource(),
            $this->onesyncDbSync(),
            $this->onesyncApi(),
            $this->sftpFeeds(),
            $this->powerSchoolOdbc(),
            $this->vpnMonitor(),
        ];
    }

    // ---- individual services --------------------------------------------------

    private function appDb(): array
    {
        $facts = [
            ['Host', (string) Config::get('DB_HOST', '127.0.0.1') . ':' . (string) Config::get('DB_PORT', '3306')],
            ['Database', (string) Config::get('DB_NAME', '?')],
        ];
        try {
            Db::connect(Db::ROLE_APP)->query('SELECT 1');
            return $this->entry('app_db', 'Application database', 'ok', 'Connected (app role).', $facts);
        } catch (\Throwable $e) {
            return $this->entry('app_db', 'Application database', 'down', 'Cannot connect: ' . $e->getMessage(), $facts);
        }
    }

    private function onesyncSource(): array
    {
        $host = trim((string) Config::get('ONESYNC_DB_HOST', ''));
        $name = trim((string) Config::get('ONESYNC_DB_NAME', ''));
        $user = trim((string) Config::get('ONESYNC_DB_USER', ''));
        $sourceIds = OneSyncResultImporter::sourceIds();
        $facts = [];
        if ($host !== '') {
            $facts[] = ['Host', $host . ':' . (string) Config::get('ONESYNC_DB_PORT', '3306')];
        }
        if ($name !== '') {
            $facts[] = ['Database', $name];
        }
        $facts[] = ['Source IDs', $sourceIds === [] ? '(none set)' : implode(', ', $sourceIds)];

        if ($host === '' || $name === '' || $user === '') {
            return $this->entry('onesync_source', 'OneSync source DB', 'disabled',
                'Not configured — set ONESYNC_DB_* to enable the OneSync DB sync.', $facts);
        }

        $ping = $this->pingMysql(
            $host,
            (string) Config::get('ONESYNC_DB_PORT', '3306'),
            $name,
            (string) Config::get('ONESYNC_DB_CHARSET', 'utf8mb4'),
            $user,
            (string) Config::get('ONESYNC_DB_PASS', '')
        );
        if ($ping['ok']) {
            return $this->entry('onesync_source', 'OneSync source DB', 'ok', 'Connected (read-only).', $facts);
        }
        return $this->entry('onesync_source', 'OneSync source DB', 'down',
            'Cannot reach OneSync DB: ' . $ping['error'], $facts);
    }

    private function onesyncDbSync(): array
    {
        try {
            $h = $this->dash->syncHealth();
        } catch (\Throwable $e) {
            return $this->entry('onesync_db_sync', 'OneSync DB sync', 'warn',
                'Could not read sync status: ' . $e->getMessage(), []);
        }
        $failed = ($h['status'] ?? null) === 'failed';
        $state = $failed ? 'down' : ($h['state'] === 'fresh' ? 'ok' : 'warn');
        $detail = match (true) {
            $h['state'] === 'never' => 'Never run — provisioning results have not been pulled from OneSync yet (bin/import_onesync_db.php).',
            $failed                 => "Last run failed {$h['label']} (bin/import_onesync_db.php).",
            $h['state'] === 'stale' => "Stale — last run {$h['label']} (expected within {$h['staleHours']}h).",
            default                 => "Last run {$h['label']}.",
        };
        $facts = [
            ['Last run at', $h['at'] === null ? '—' : (string) str_replace('T', ' ', (string) $h['at'])],
        ];
        if (is_array($h['counts'] ?? null)) {
            $facts[] = ['Accounts updated', (string) (int) ($h['counts']['upserted'] ?? 0)];
            $facts[] = ['Failed exports', (string) (int) ($h['counts']['failed'] ?? 0)];
        }
        return $this->entry('onesync_db_sync', 'OneSync DB sync', $state, $detail, $facts);
    }

    /**
     * The reduced write-back API: OneSync only posts usernames and initial
     * passwords for new accounts — provisioning results come from the DB sync
     * above. Reports configuration presence (key + password encryption), not a
     * round-trip.
     */
    private function onesyncApi(): array
    {
        $keySet = trim((string) Config::get('ONESYNC_API_KEY', '')) !== '';
        $pwReady = Crypto::configured();
        $facts = [
            ['Endpoints', '/api/onesync/username · /api/onesync/password'],
            ['API key', $keySet ? 'set' : '(not set)'],
            ['Password encryption', $pwReady ? 'configured' : Crypto::KEY_ENV . ' not set'],
        ];
        if (!$keySet) {
            return $this->entry('onesync_api', 'OneSync write-back API', 'disabled',
                'Disabled — set ONESYNC_API_KEY so OneSync can post usernames + initial passwords.', $facts);
        }
        if (!$pwReady) {
            return $this->entry('onesync_api', 'OneSync write-back API', 'warn',
                'Usernames only — ' . Crypto::KEY_ENV . ' is not set, so /password returns 503.', $facts);
        }
        return $this->entry('onesync_api', 'OneSync write-back API', 'ok',
            'Enabled — OneSync posts usernames + initial passwords for new accounts.', $facts);
    }

    private function sftpFeeds(): array
    {
        $sources = FeedSync::configuredSources();
        $facts = [['Configured feeds', $sources === [] ? '(none)' : implode(', ', $sources)]];
        if ($sources === []) {
            return $this->entry('sftp_feeds', 'SFTP feeds', 'disabled',
                'No SFTP feeds configured (set SFTP_HOST + SFTP_<source>_DIR).', $facts);
        }
        return $this->entry('sftp_feeds', 'SFTP feeds', 'ok',
            count($sources) . ' feed source(s) configured on ' . (string) Config::get('SFTP_HOST', '?') . '.', $facts);
    }

    private function powerSchoolOdbc(): array
    {
        $configured = FeedSync::powerSchoolOdbcEnabled();
        $ext = extension_loaded('pdo_odbc');
        $facts = [
            ['ODBC DSN', $configured ? 'set' : '(not set)'],
            ['pdo_odbc ext', $ext ? 'loaded' : 'missing'],
        ];
        if (!$configured) {
            return $this->entry('powerschool_odbc', 'PowerSchool (Oracle ODBC)', 'disabled',
                'Not configured — set PS_ODBC_DSN to read PowerSchool directly.', $facts);
        }
        if (!$ext) {
            return $this->entry('powerschool_odbc', 'PowerSchool (Oracle ODBC)', 'warn',
                'Configured, but the pdo_odbc PHP extension is not loaded on this host.', $facts);
        }
        return $this->entry('powerschool_odbc', 'PowerSchool (Oracle ODBC)', 'ok',
            'Configured (used by the feed pull and students sync).', $facts);
    }

    private function vpnMonitor(): array
    {
        if (!$this->vpn->configured()) {
            return $this->entry('vpn', 'PowerSchool VPN', 'disabled',
                'Monitor not configured — set VPN_MONITOR_URL.', [['Monitor', '(not set)']]);
        }
        $snap = $this->vpn->snapshot();
        $facts = [['Monitor', $this->vpn->baseUrl()]];
        if (!$snap['ok']) {
            return $this->entry('vpn', 'PowerSchool VPN', 'down',
                'Cannot reach the VPN monitor: ' . (string) $snap['error'], $facts);
        }
        $overall = (string) ($snap['data']['overall'] ?? 'unknown');
        $state = match ($overall) {
            'ok'   => 'ok',
            'warn' => 'warn',
            'down' => 'down',
            default => 'warn',
        };
        return $this->entry('vpn', 'PowerSchool VPN', $state,
            'Tunnel overall: ' . strtoupper($overall) . '.', $facts);
    }

    // ---- helpers --------------------------------------------------------------

    private function entry(string $key, string $label, string $state, string $detail, array $facts): array
    {
        return ['key' => $key, 'label' => $label, 'state' => $state, 'detail' => $detail, 'facts' => $facts];
    }

    /**
     * Bounded MySQL connectivity probe — a fresh (uncached) connection with a
     * short connect timeout so an unreachable host fails fast instead of hanging
     * the admin page. Returns a result envelope, never throws.
     *
     * @return array{ok:bool,error:?string}
     */
    private function pingMysql(string $host, string $port, string $name, string $charset, string $user, string $pass): array
    {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE    => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT    => $this->pingTimeout,
            ]);
            $pdo->query('SELECT 1');
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
