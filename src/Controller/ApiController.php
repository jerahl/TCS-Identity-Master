<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Import\SyncStatusImporter;
use App\Import\WritebackImporter;
use App\Support\ApiLog;

/**
 * Machine-to-machine write-back API for OneSync. OneSync executes an API call on
 * each event (username minted, account provisioned to a destination) instead of
 * dropping CSVs. Token-authenticated (no session/CSRF); JSON in, JSON out.
 *
 *   POST /api/onesync/username     {uniqueId, username, email?, upn?}
 *   POST /api/onesync/sync-status  {uniqueId, destination, action, status, message?, timestamp?}
 *   GET  /api/onesync/ping         health check (still requires the token)
 *
 * Both write endpoints accept a single event OR a JSON array of events (batch).
 * Auth: send the key as `Authorization: Bearer <key>` or `X-API-Key: <key>`.
 * The key is ONESYNC_API_KEY; if unset, the API is disabled (503).
 *
 * Reuses the write-back importers, so the same guardrails apply — usernames are
 * immutable once locked, status upserts one row per (person, destination), and it
 * runs as the limited write-back DB role.
 */
final class ApiController
{
    private ?string $rawBody = null;
    private string $parseError = 'invalid or empty JSON body';

    /** Raw request body, read once (php://input isn't always re-readable). */
    private function rawBody(): string
    {
        if ($this->rawBody === null) {
            $b = file_get_contents('php://input');
            $this->rawBody = $b === false ? '' : $b;
        }
        return $this->rawBody;
    }

    /**
     * Write one debug-log line describing this request + its outcome. Captures
     * exactly what's needed to see why OneSync failed: which header carried the
     * token (masked), whether it matched, the body, and the response status.
     *
     * @param array<string,mixed> $ctx
     */
    private function logRequest(string $endpoint, int $status, array $ctx = []): void
    {
        if (!ApiLog::enabled()) {
            return;
        }
        $tok = $this->presentedToken();
        $scheme = stripos((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'Bearer ') === 0
            ? 'bearer' : (isset($_SERVER['HTTP_X_API_KEY']) ? 'x-api-key' : 'none');
        $body = $this->rawBody();
        ApiLog::write([
            'endpoint'       => $endpoint,
            'status'         => $status,
            'method'         => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'ip'             => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'content_type'   => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            'auth_scheme'    => $scheme,
            'token_present'  => $tok !== null && $tok !== '',
            'token_len'      => $tok !== null ? strlen($tok) : 0,
            'token_preview'  => self::mask($tok),
            'key_configured' => trim((string) Config::get('ONESYNC_API_KEY', '')) !== '',
            'body'           => mb_substr($body, 0, 2000),
            'body_len'       => strlen($body),
        ] + $ctx);
    }

    /** Mask a secret for the log: first/last 3 chars only. */
    private static function mask(?string $s): string
    {
        $s = (string) $s;
        if ($s === '') {
            return '';
        }
        return strlen($s) <= 8 ? '***' : substr($s, 0, 3) . '…' . substr($s, -3);
    }

    /** Constant-time token comparison; false if no key is configured. */
    public static function tokenMatches(?string $provided, ?string $expected): bool
    {
        $expected = (string) $expected;
        if ($expected === '' || $provided === null || $provided === '') {
            return false;
        }
        return hash_equals($expected, $provided);
    }

    /** Pull the bearer/api-key token from request headers. */
    private function presentedToken(): ?string
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $key = $_SERVER['HTTP_X_API_KEY'] ?? null;
        return $key !== null ? trim((string) $key) : null;
    }

    /** Gate: 503 if disabled, 401 if the token is missing/wrong. Returns null when OK. */
    private function authError(string $endpoint): ?string
    {
        $expected = Config::get('ONESYNC_API_KEY');
        if ($expected === null || trim((string) $expected) === '') {
            $this->logRequest($endpoint, 503, ['reason' => 'ONESYNC_API_KEY not set']);
            return $this->json(503, ['ok' => false, 'error' => 'API disabled (ONESYNC_API_KEY not set).']);
        }
        if (!self::tokenMatches($this->presentedToken(), (string) $expected)) {
            $this->logRequest($endpoint, 401, ['reason' => 'token missing or mismatch']);
            return $this->json(401, ['ok' => false, 'error' => 'Unauthorized.']);
        }
        return null;
    }

    public function ping(): string
    {
        if (($err = $this->authError('ping')) !== null) {
            return $err;
        }
        $this->logRequest('ping', 200);
        return $this->json(200, ['ok' => true, 'service' => 'tcs-identity onesync api']);
    }

    public function username(): string
    {
        if (($err = $this->authError('username')) !== null) {
            return $err;
        }
        $events = $this->readEvents();
        if ($events === null) {
            $this->logRequest('username', 400, ['reason' => $this->parseError]);
            return $this->json(400, ['ok' => false, 'error' => $this->parseError]);
        }

        $importer = new WritebackImporter();
        $results = [];
        $anyError = false;
        foreach ($events as $e) {
            if (trim((string) ($e['uniqueId'] ?? '')) === '') {
                $results[] = ['ok' => false, 'error' => 'uniqueId is required'];
                $anyError = true;
                continue;
            }
            try {
                $r = $importer->applyEvent($e);
                $results[] = ['ok' => true, 'uniqueId' => $r['uuid'], 'username' => $r['username'], 'outcome' => $r['outcome'], 'detail' => $r['detail']];
                $anyError = $anyError || $r['outcome'] === 'error';
            } catch (\Throwable $ex) {
                error_log('[idm] api username: ' . $ex->getMessage());
                $results[] = ['ok' => false, 'error' => 'apply failed'];
                $anyError = true;
            }
        }
        return $this->respond('username', $results, $anyError);
    }

    public function syncStatus(): string
    {
        if (($err = $this->authError('sync-status')) !== null) {
            return $err;
        }
        $events = $this->readEvents();
        if ($events === null) {
            $this->logRequest('sync-status', 400, ['reason' => $this->parseError]);
            return $this->json(400, ['ok' => false, 'error' => $this->parseError]);
        }

        $importer = new SyncStatusImporter();
        $results = [];
        $anyError = false;
        foreach ($events as $e) {
            $uuid = trim((string) ($e['uniqueId'] ?? ''));
            $dest = trim((string) ($e['destination'] ?? ''));
            if ($uuid === '' || $dest === '') {
                $results[] = ['ok' => false, 'error' => 'uniqueId and destination are required'];
                $anyError = true;
                continue;
            }
            try {
                $r = $importer->applyEvent($e);
                $results[] = ['ok' => $r['outcome'] !== 'error', 'uniqueId' => $uuid, 'destination' => $dest, 'outcome' => $r['outcome']];
                $anyError = $anyError || $r['outcome'] === 'error';
            } catch (\Throwable $ex) {
                error_log('[idm] api sync-status: ' . $ex->getMessage());
                $results[] = ['ok' => false, 'error' => 'apply failed'];
                $anyError = true;
            }
        }
        return $this->respond('sync-status', $results, $anyError);
    }

    /**
     * Decode the JSON request body into a list of event objects. Accepts a single
     * object or an array of objects. Returns null on malformed/empty input.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function readEvents(): ?array
    {
        $body = $this->rawBody();
        if (trim($body) === '') {
            $this->parseError = 'empty request body';
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->parseError = 'not valid JSON: ' . json_last_error_msg()
                . '. Expected a JSON object with named keys, e.g. {"uniqueId":"...","destination":"..."}';
            return null;
        }
        // A single event is an associative array; a batch is a list.
        $isList = array_is_list($data);
        $events = $isList ? $data : [$data];
        foreach ($events as $e) {
            if (!is_array($e)) {
                return null;
            }
        }
        return $events === [] ? null : $events;
    }

    /** One result for a single event; the array for a batch. */
    private function respond(string $endpoint, array $results, bool $anyError): string
    {
        if (count($results) === 1) {
            $one = $results[0];
            $status = $one['ok'] ? 200 : 422;
            $this->logRequest($endpoint, $status, ['results' => $results]);
            return $this->json($status, $one);
        }
        $status = $anyError ? 207 : 200;
        $this->logRequest($endpoint, $status, ['results' => $results]);
        return $this->json($status, ['ok' => !$anyError, 'results' => $results]);
    }

    /** @param array<string,mixed> $payload */
    private function json(int $status, array $payload): string
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
