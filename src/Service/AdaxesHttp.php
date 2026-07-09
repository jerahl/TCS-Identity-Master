<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * Shared Adaxes REST auth + HTTP transport, factored out of AdaxesService so the
 * read service and the AdaxesWriter (direct AD provisioning) speak to Adaxes the
 * same way: same token/handshake authentication, same TLS/CA handling, same
 * injectable $fetch for tests, same graceful degradation (never throw — every
 * path returns a decoded envelope). The consuming class owns its own constructor
 * (it sets these properties) and its own configured()/business methods; this
 * trait only carries the plumbing both share.
 *
 * @phpstan-type HttpResponse array{status:int, body:string}
 */
trait AdaxesHttp
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $token;
    private int $timeout;
    private string $sessionPath;
    private string $tokenPath;

    /** Token + session resolved from the username/password handshake, per instance. */
    private ?string $resolvedToken = null;
    private ?string $resolvedSessionId = null;
    private bool $authAttempted = false;
    private ?string $authError = null;

    /** @var callable(string,string,array<string,string>,?string):?array{status:int,body:string} */
    private $fetch;

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Resolve the security token sent as `Adm-Authorization`. A static token wins;
     * otherwise run the legacy two-step handshake with the service
     * username/password (create session → obtain token) and cache the result for
     * this instance. Returns null and sets $authError on failure.
     */
    private function authToken(): ?string
    {
        if ($this->token !== '') {
            return $this->token;
        }
        if ($this->resolvedToken !== null) {
            return $this->resolvedToken;
        }
        if ($this->authAttempted) {
            return null; // don't retry a failed handshake repeatedly within a request
        }
        $this->authAttempted = true;

        if ($this->username === '' || $this->password === '') {
            $this->authError = 'no ADAXES_TOKEN and no username/password';
            return null;
        }

        // 1) Create an authentication session (POST credentials).
        $sessUrl = $this->baseUrl . '/' . $this->sessionPath;
        $resp = ($this->fetch)('POST', $sessUrl, self::jsonHeaders(), (string) json_encode(['username' => $this->username, 'password' => $this->password]));
        $this->debugLog('POST', $sessUrl, $resp['status'] ?? 0, '(authSessions/create — body redacted)');
        $session = $this->decode($resp);
        if (!$session['ok']) {
            $this->authError = 'session create failed (HTTP ' . ($resp['status'] ?? 0) . ')';
            return null;
        }
        $sessionId = $session['data']['sessionId'] ?? $session['data']['id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            $this->authError = 'no sessionId in authSessions/create response';
            return null;
        }
        $this->resolvedSessionId = $sessionId;

        // 2) Exchange the session for a security token.
        $tokUrl = $this->baseUrl . '/' . $this->tokenPath;
        $resp2 = ($this->fetch)('POST', $tokUrl, self::jsonHeaders(), (string) json_encode(['sessionId' => $sessionId]));
        $this->debugLog('POST', $tokUrl, $resp2['status'] ?? 0, '(auth — token redacted)');
        $tok = $this->decode($resp2);
        if (!$tok['ok']) {
            $this->authError = 'token request failed (HTTP ' . ($resp2['status'] ?? 0) . ')';
            return null;
        }
        $token = $tok['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            $this->authError = 'no token in auth response';
            return null;
        }
        return $this->resolvedToken = $token;
    }

    /** Headers for the unauthenticated handshake POSTs. @return array<string,string> */
    private static function jsonHeaders(): array
    {
        return ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    /**
     * Tear down a handshake-minted session + token (DELETE the token, then the
     * session) so they don't linger until auto-expiry. Best-effort and idempotent
     * — a static token minted no session, so this no-ops. Resets the cached auth
     * so a later call re-authenticates cleanly.
     */
    private function endSession(): void
    {
        if ($this->resolvedSessionId === null) {
            return;
        }
        $sessionId = $this->resolvedSessionId;
        $token = $this->resolvedToken;
        // Reset first so we never double-clean and a re-entry re-handshakes.
        $this->resolvedSessionId = null;
        $this->resolvedToken = null;
        $this->authAttempted = false;

        try {
            if ($token !== null) {
                $tokUrl = $this->baseUrl . '/' . $this->tokenPath . '?token=' . rawurlencode($token);
                ($this->fetch)('DELETE', $tokUrl, ['Adm-Authorization' => $token, 'Accept' => 'application/json'], null);
            }
            // The terminate endpoint is the sessions collection (sans "/create").
            $sessionsPath = (string) preg_replace('#/create$#', '', $this->sessionPath);
            $sessUrl = $this->baseUrl . '/' . $sessionsPath . '?id=' . rawurlencode($sessionId);
            ($this->fetch)('DELETE', $sessUrl, ['Accept' => 'application/json'], null);
        } catch (\Throwable) {
            // Cleanup is best-effort; the session/token will auto-expire anyway.
        }
    }

    /**
     * Decode an HTTP response into JSON, classifying failures into a message.
     *
     * @param array{status:int,body:string}|null $resp
     * @return array{ok:bool, error:?string, data:array<string,mixed>}
     */
    private function decode(?array $resp): array
    {
        if ($resp === null) {
            return ['ok' => false, 'error' => 'Adaxes is unreachable.', 'data' => []];
        }
        $status = $resp['status'] ?? 0;
        if ($status === 401 || $status === 403) {
            return ['ok' => false, 'error' => 'Adaxes rejected the credentials (HTTP ' . $status . ') — check ADAXES_TOKEN (or username/password).', 'data' => []];
        }
        if ($status >= 300 && $status < 400) {
            // A redirect is usually one of two things: a wrong path (the REST API
            // lives under {base}/api — a missing api/ segment gets redirected) or
            // an unauthenticated request bounced to a login. The Location header
            // (appended by request()) tells which.
            return ['ok' => false, 'error' => 'Adaxes redirected the request (HTTP ' . $status . ') — likely a wrong path (the REST API is under {base}/api) or an unauthenticated request. Check ADAXES_BASE_URL / ADAXES_OBJECTS_PATH and ADAXES_TOKEN.', 'data' => []];
        }
        if ($status < 200 || $status >= 300) {
            return ['ok' => false, 'error' => 'Adaxes returned HTTP ' . $status . '.', 'data' => []];
        }
        $data = json_decode((string) ($resp['body'] ?? ''), true);
        if (!is_array($data)) {
            // An empty body on a 2xx is a valid "no content" success (some write
            // endpoints return 204 with nothing) — treat it as an empty payload.
            if (trim((string) ($resp['body'] ?? '')) === '') {
                return ['ok' => true, 'error' => null, 'data' => []];
            }
            return ['ok' => false, 'error' => 'Adaxes returned invalid JSON.', 'data' => []];
        }
        return ['ok' => true, 'error' => null, 'data' => $data];
    }

    /**
     * Perform a request and decode it, annotating any error with the method +
     * URL path (no query string, so no PII) so a misconfigured endpoint is
     * obvious, and writing an optional debug line.
     *
     * @return array{ok:bool, error:?string, data:array<string,mixed>, status:int}
     */
    private function request(string $method, string $url, ?string $body = null): array
    {
        $token = $this->authToken();
        if ($token === null) {
            return ['ok' => false, 'error' => 'Adaxes authentication failed: ' . ($this->authError ?? 'unknown') . '.', 'data' => [], 'status' => 0];
        }
        $headers = ['Adm-Authorization' => $token, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $resp = ($this->fetch)($method, $url, $headers, $body);
        $decoded = $this->decode($resp);
        $status = $resp['status'] ?? 0;
        $location = is_array($resp) ? ($resp['location'] ?? null) : null;
        if (!$decoded['ok'] && $status > 0 && $decoded['error'] !== null) {
            $decoded['error'] .= ' [' . $method . ' ' . self::urlPath($url) . ']';
            if ($location !== null && $location !== '') {
                $decoded['error'] .= ' (redirected to ' . self::urlPath((string) $location) . ')';
            }
        }
        $this->debugLog($method, $url, $status, $resp === null ? null : (string) ($resp['body'] ?? ''), $location);
        $decoded['status'] = $status;
        return $decoded;
    }

    /** The URL without its query string (drops the search filter / PII). */
    private static function urlPath(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false ? $url : substr($url, 0, $q);
    }

    /**
     * Append a request/response line to the Adaxes debug log when ADAXES_DEBUG is
     * on. Logs the full URL + a response snippet so an operator can see exactly
     * what was sent and returned. Never logs the Authorization header. NOTE: the
     * snippet can contain directory data (PII) and the URL carries the search
     * filter — enable only while troubleshooting, then turn it back off.
     */
    private function debugLog(string $method, string $url, int $status, ?string $body, ?string $location = null): void
    {
        if (!Config::bool('ADAXES_DEBUG', false)) {
            return;
        }
        $snippet = $body === null
            ? '(no response — transport failure)'
            : substr((string) preg_replace('/\s+/', ' ', $body), 0, 500);
        $loc = ($location !== null && $location !== '') ? ' -> Location: ' . $location : '';
        $line = sprintf("[%s] %s %s -> HTTP %d%s | %s", gmdate('c'), $method, $url, $status, $loc, $snippet);

        // Write to the configured file; if that fails (dir missing / not writable
        // by php-fpm) fall back to the PHP error log so debug output is never lost.
        $path = (string) Config::get('ADAXES_LOG', '/var/idm/adaxes_debug.log');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        if (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[idm][adaxes] ' . $line);
        }
    }

    /**
     * Real HTTP transport. Short timeout; returns the status + body, or null on a
     * transport-level failure (DNS, connect, timeout). Honors an internal CA
     * bundle (ADAXES_CA_FILE); TLS verification stays ON unless an operator
     * explicitly disables it (ADAXES_VERIFY_TLS=false) for a self-signed host.
     *
     * @param array<string,string> $headers
     * @return array{status:int,body:string}|null
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $body): ?array
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $verifyTls = Config::bool('ADAXES_VERIFY_TLS', true);
        $caFile = (string) Config::get('ADAXES_CA_FILE', '');

        $ctx = stream_context_create([
            'http' => [
                'method'          => $method,
                'timeout'         => $this->timeout,
                'ignore_errors'   => true,
                'follow_location' => 0,   // capture the auth redirect instead of chasing a login page
                'max_redirects'   => 0,
                'header'          => $headerLines,
                'content'         => $body ?? '',
            ],
            'ssl' => array_filter([
                'verify_peer'      => $verifyTls,
                'verify_peer_name' => $verifyTls,
                'cafile'           => $caFile !== '' ? $caFile : null,
            ], static fn($v) => $v !== null),
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $headers = $http_response_header ?? [];
        return [
            'status'   => self::statusFromHeaders($headers),
            'body'     => $raw,
            'location' => self::headerValue($headers, 'Location'),
        ];
    }

    /** First value of the named response header (case-insensitive), or null. */
    private static function headerValue(array $headers, string $name): ?string
    {
        $needle = strtolower($name) . ':';
        foreach ($headers as $line) {
            $line = (string) $line;
            if (str_starts_with(strtolower($line), $needle)) {
                return trim(substr($line, strlen($needle)));
            }
        }
        return null;
    }

    /** Parse the numeric status code out of the $http_response_header lines. */
    private static function statusFromHeaders(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) {
                return (int) $m[1]; // last status line wins (handles redirects)
            }
        }
        return 0;
    }
}
