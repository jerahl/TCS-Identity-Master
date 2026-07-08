<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * GAM-backed transport for direct Google Workspace provisioning — an alternative
 * to GoogleWorkspaceService's built-in Admin SDK HTTP client, selected with
 * GOOGLE_BACKEND=gam. Instead of the app holding a service-account key and
 * minting JWTs itself, every directory operation shells out to **GAM**
 * (https://github.com/GAM-team/GAM), the CLI Google Workspace admins already
 * run — so auth lives entirely in GAM's own project/config (`gam config`,
 * GAMCFGDIR) and this app never touches Google credentials.
 *
 * Scope is deliberately identical to the API backend: get one user, search
 * users, create, update (incl. suspend/restore). Results are translated back
 * into Admin-SDK-shaped user arrays so GoogleWorkspaceService's correlation,
 * comparison, and envelope logic is shared verbatim between backends.
 *
 * Design mirrors the other service clients: config-gated, the process runner is
 * injectable (unit tests exercise the argv/parsing with no gam installed), and
 * it NEVER throws — every path returns an ok/error envelope. Commands are
 * executed argv-style (no shell), so person data can't inject into a command
 * line; the initial password is never on the argv either (GAM's `password
 * random` generates it on Google's side of the call).
 *
 * Targets GAM7 (the merged GAM-team/GAM project): `info user … formatjson`
 * prints the user resource as JSON, and `print users query … formatjson`
 * prints CSV whose JSON column holds each matching resource.
 *
 * @phpstan-type RunResult array{status:int, stdout:string, stderr:string}
 * @phpstan-type ReadResult array{ok:bool, error:?string, found:bool, data:array<string,mixed>}
 * @phpstan-type GamWriteResult array{ok:bool, error:?string, data:array<string,mixed>}
 */
final class GamClient
{
    private string $gamPath;
    private string $configDir;
    private int $timeout;

    /** @var callable(array<int,string>):?array{status:int,stdout:string,stderr:string} */
    private $runner;

    /**
     * @param callable(array<int,string>):?array{status:int,stdout:string,stderr:string}|null $runner
     *        ($argv) → ['status'=>int,'stdout'=>string,'stderr'=>string], or null when
     *        the process could not run at all (missing binary, timeout).
     */
    public function __construct(
        ?string $gamPath = null,
        ?string $configDir = null,
        ?int $timeout = null,
        ?callable $runner = null,
    ) {
        $this->gamPath = trim($gamPath ?? (string) Config::get('GAM_PATH', ''));
        $this->configDir = trim($configDir ?? (string) Config::get('GAM_CONFIG_DIR', ''));
        $this->timeout = max(1, $timeout ?? (int) Config::get('GAM_TIMEOUT', '60'));
        $this->runner = $runner ?? fn(array $argv): ?array => $this->exec($argv);
    }

    /** The GAM backend is usable once the binary path is set. */
    public function configured(): bool
    {
        return $this->gamPath !== '';
    }

    // ---- reads ---------------------------------------------------------------

    /**
     * Fetch one user by id or primaryEmail (`gam info user <key> formatjson`).
     * "Does not exist" is a clean not-found, mirroring the API backend's 404.
     *
     * @return ReadResult
     */
    public function getUser(string $userKey): array
    {
        $res = $this->run(['info', 'user', $userKey, 'formatjson']);
        if ($res === null) {
            return self::readFail($this->unreachable());
        }
        if ($res['status'] !== 0) {
            if (self::isNotFound($res['stderr'])) {
                return ['ok' => true, 'error' => null, 'found' => false, 'data' => []];
            }
            return self::readFail($this->gamError($res, 'info user'));
        }
        $data = self::firstJsonObject($res['stdout']);
        if ($data === null) {
            return self::readFail('GAM returned unparseable output for info user (expected formatjson).');
        }
        return ['ok' => true, 'error' => null, 'found' => true, 'data' => $data];
    }

    /**
     * Search the directory with an Admin SDK `query` string and return the first
     * hit (`gam print users query <q> allfields formatjson` → CSV, JSON column).
     *
     * @return ReadResult
     */
    public function searchUsers(string $query): array
    {
        $res = $this->run(['print', 'users', 'query', $query, 'allfields', 'formatjson']);
        if ($res === null) {
            return self::readFail($this->unreachable());
        }
        if ($res['status'] !== 0) {
            return self::readFail($this->gamError($res, 'print users'));
        }
        $data = self::firstCsvJsonRow($res['stdout']);
        if ($data === null) {
            return ['ok' => true, 'error' => null, 'found' => false, 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'found' => true, 'data' => $data];
    }

    // ---- writes ---------------------------------------------------------------

    /**
     * Create a user from an Admin-SDK-shaped insert body (the same array
     * GoogleWorkspaceService::buildCreateBody produces). The body's password is
     * deliberately IGNORED — a command line is visible in the process list, so
     * GAM's `password random` sets the random initial password instead — and
     * the fresh resource is re-read so the caller gets the new Google id.
     *
     * @param array<string,mixed> $body
     * @return GamWriteResult
     */
    public function createUser(array $body): array
    {
        $email = trim((string) ($body['primaryEmail'] ?? ''));
        if ($email === '') {
            return self::writeFail('No primaryEmail in the create request.');
        }
        $argv = array_merge(['create', 'user', $email], self::personArgs($body), ['password', 'random', 'changepassword', 'on']);
        $res = $this->run($argv);
        if ($res === null) {
            return self::writeFail($this->unreachable());
        }
        if ($res['status'] !== 0) {
            return self::writeFail($this->gamError($res, 'create user'));
        }
        return $this->readBack($email, $body + ['suspended' => false]);
    }

    /**
     * Update a user from an Admin-SDK-shaped patch body: name, orgUnitPath,
     * externalIds, and/or suspended (so suspend/restore is `suspended on|off`).
     *
     * @param array<string,mixed> $body
     * @return GamWriteResult
     */
    public function updateUser(string $userKey, array $body): array
    {
        $argv = self::personArgs($body);
        if (array_key_exists('suspended', $body)) {
            $argv[] = 'suspended';
            $argv[] = $body['suspended'] ? 'on' : 'off';
        }
        if ($argv === []) {
            return self::writeFail('Nothing to update.');
        }
        $res = $this->run(array_merge(['update', 'user', $userKey], $argv));
        if ($res === null) {
            return self::writeFail($this->unreachable());
        }
        if ($res['status'] !== 0) {
            return self::writeFail($this->gamError($res, 'update user'));
        }
        return $this->readBack($userKey, $body);
    }

    // ---- internals -------------------------------------------------------------

    /**
     * Translate the shared Admin-SDK body fields into GAM arguments. Password is
     * intentionally not translated (see createUser).
     *
     * @param array<string,mixed> $body
     * @return array<int,string>
     */
    private static function personArgs(array $body): array
    {
        $argv = [];
        $name = is_array($body['name'] ?? null) ? $body['name'] : [];
        if (($name['givenName'] ?? '') !== '') {
            $argv[] = 'firstname';
            $argv[] = (string) $name['givenName'];
        }
        if (($name['familyName'] ?? '') !== '') {
            $argv[] = 'lastname';
            $argv[] = (string) $name['familyName'];
        }
        if (($body['orgUnitPath'] ?? '') !== '') {
            $argv[] = 'org';
            $argv[] = (string) $body['orgUnitPath'];
        }
        $ext = $body['externalIds'] ?? null;
        if (is_array($ext) && isset($ext[0]['value']) && trim((string) $ext[0]['value']) !== '') {
            $argv[] = 'externalid';
            $argv[] = (string) ($ext[0]['type'] ?? 'organization');
            $argv[] = (string) $ext[0]['value'];
        }
        return $argv;
    }

    /**
     * After a successful write, re-read the account so the caller gets live
     * attributes (incl. the Google id for the crosswalk). If the read-back
     * itself fails (e.g. propagation lag), the WRITE still succeeded — degrade
     * to the fields we set rather than reporting a false failure.
     *
     * @param array<string,mixed> $written
     * @return GamWriteResult
     */
    private function readBack(string $userKey, array $written): array
    {
        $read = $this->getUser($userKey);
        if ($read['ok'] && $read['found']) {
            return ['ok' => true, 'error' => null, 'data' => $read['data']];
        }
        unset($written['password'], $written['changePasswordAtNextLogin']);
        if (str_contains($userKey, '@') && !isset($written['primaryEmail'])) {
            $written['primaryEmail'] = $userKey;
        }
        return ['ok' => true, 'error' => null, 'data' => $written];
    }

    /** Run a gam subcommand (argv appended to the binary). Null = could not run. */
    private function run(array $args): ?array
    {
        if (!$this->configured()) {
            return null;
        }
        return ($this->runner)(array_merge([$this->gamPath], $args));
    }

    /** GAM's not-found diagnostics all carry "Does not exist". */
    private static function isNotFound(string $stderr): bool
    {
        return stripos($stderr, 'does not exist') !== false;
    }

    /** First JSON object in stdout (formatjson output, tolerating banner lines). */
    private static function firstJsonObject(string $stdout): ?array
    {
        $start = strpos($stdout, '{');
        if ($start === false) {
            return null;
        }
        $data = json_decode(substr($stdout, $start), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Parse `print … formatjson` CSV output and return the first row's JSON
     * column decoded, or null when there are no data rows.
     */
    private static function firstCsvJsonRow(string $stdout): ?array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($stdout)) ?: [];
        $jsonCol = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if ($jsonCol === null) {
                // Header row: find the JSON column.
                $idx = array_search('JSON', $cells, true);
                if ($idx === false) {
                    return null; // not formatjson CSV (or an error banner)
                }
                $jsonCol = (int) $idx;
                continue;
            }
            $raw = (string) ($cells[$jsonCol] ?? '');
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    private function unreachable(): string
    {
        return 'GAM did not run (check GAM_PATH=' . ($this->gamPath !== '' ? $this->gamPath : 'unset') . ' and GAM_TIMEOUT).';
    }

    /** @param array{status:int,stdout:string,stderr:string} $res */
    private function gamError(array $res, string $what): string
    {
        $detail = trim($res['stderr']) !== '' ? trim($res['stderr']) : trim($res['stdout']);
        $detail = trim((string) preg_replace('/\s+/', ' ', $detail));
        if (strlen($detail) > 300) {
            $detail = substr($detail, 0, 300) . '…';
        }
        return 'GAM ' . $what . ' failed (exit ' . $res['status'] . '): ' . ($detail !== '' ? $detail : 'no output');
    }

    /** @return ReadResult */
    private static function readFail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'found' => false, 'data' => []];
    }

    /** @return GamWriteResult */
    private static function writeFail(string $error): array
    {
        return ['ok' => false, 'error' => $error, 'data' => []];
    }

    /**
     * Real process runner: argv-style proc_open (no shell), stdout/stderr
     * captured, hard deadline of GAM_TIMEOUT seconds. GAMCFGDIR is exported when
     * GAM_CONFIG_DIR is set so the web/app user can share an ops-managed GAM
     * setup. Returns null when the process can't start or exceeds the deadline.
     *
     * @param array<int,string> $argv
     * @return array{status:int,stdout:string,stderr:string}|null
     */
    private function exec(array $argv): ?array
    {
        $env = null;
        if ($this->configDir !== '') {
            $env = getenv() + ['GAMCFGDIR' => $this->configDir];
        }
        $proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $this->timeout;
        while (true) {
            $status = proc_get_status($proc);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (!$status['running']) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['status' => (int) $status['exitcode'], 'stdout' => $stdout, 'stderr' => $stderr];
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return null; // timed out — treated as unreachable
            }
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 0, 200_000);
        }
    }
}
