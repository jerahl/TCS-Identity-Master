<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Import\SyncStatusImporter;
use App\Import\WritebackImporter;

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
    private function authError(): ?string
    {
        $expected = Config::get('ONESYNC_API_KEY');
        if ($expected === null || trim((string) $expected) === '') {
            return $this->json(503, ['ok' => false, 'error' => 'API disabled (ONESYNC_API_KEY not set).']);
        }
        if (!self::tokenMatches($this->presentedToken(), (string) $expected)) {
            return $this->json(401, ['ok' => false, 'error' => 'Unauthorized.']);
        }
        return null;
    }

    public function ping(): string
    {
        if (($err = $this->authError()) !== null) {
            return $err;
        }
        return $this->json(200, ['ok' => true, 'service' => 'tcs-identity onesync api']);
    }

    public function username(): string
    {
        if (($err = $this->authError()) !== null) {
            return $err;
        }
        $events = $this->readEvents();
        if ($events === null) {
            return $this->json(400, ['ok' => false, 'error' => 'Invalid or empty JSON body.']);
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
        return $this->respond($results, $anyError);
    }

    public function syncStatus(): string
    {
        if (($err = $this->authError()) !== null) {
            return $err;
        }
        $events = $this->readEvents();
        if ($events === null) {
            return $this->json(400, ['ok' => false, 'error' => 'Invalid or empty JSON body.']);
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
        return $this->respond($results, $anyError);
    }

    /**
     * Decode the JSON request body into a list of event objects. Accepts a single
     * object or an array of objects. Returns null on malformed/empty input.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function readEvents(): ?array
    {
        $body = file_get_contents('php://input');
        if ($body === false || trim($body) === '') {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
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
    private function respond(array $results, bool $anyError): string
    {
        $status = $anyError ? 207 : 200;
        if (count($results) === 1) {
            $one = $results[0];
            return $this->json($one['ok'] ? 200 : 422, $one);
        }
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
