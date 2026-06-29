<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * Read-only client for the pseast-vpn-monitor JSON API.
 *
 * The monitor (a separate read-only process) exposes /api/status and
 * /api/history; it normally binds to localhost on this server, so the browser
 * can't reach it directly — this service fetches it server-side and hands the
 * decoded snapshot to the VPN status page. It never controls the VPN; it only
 * relays what the monitor observed.
 *
 * Configured via VPN_MONITOR_URL (e.g. http://127.0.0.1:8787). The HTTP fetch is
 * injectable so the logic is unit-testable without a running monitor.
 */
final class VpnMonitorService
{
    private string $baseUrl;
    private int $timeout;
    /** @var callable(string):?string */
    private $fetch;

    /** @param callable(string):?string|null $fetch returns the response body, or null on failure */
    public function __construct(?string $baseUrl = null, ?int $timeout = null, ?callable $fetch = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) Config::get('VPN_MONITOR_URL', ''), '/');
        $this->timeout = $timeout ?? max(1, (int) Config::get('VPN_MONITOR_TIMEOUT', '4'));
        $this->fetch = $fetch ?? fn(string $url): ?string => $this->httpGet($url);
    }

    public function configured(): bool
    {
        return $this->baseUrl !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /** The current status snapshot: ['ok'=>bool, 'error'=>?string, 'data'=>?array]. */
    public function snapshot(): array
    {
        return $this->fetchJson('/api/status');
    }

    /** The uptime/history view: ['ok'=>bool, 'error'=>?string, 'data'=>?array]. */
    public function history(): array
    {
        return $this->fetchJson('/api/history');
    }

    /** Map a monitor status to a layout badge modifier (matches Present/import badges). */
    public static function tone(string $status): string
    {
        return [
            'ok'      => 'active',
            'warn'    => 'pending',
            'down'    => 'terminated',
            'unknown' => 'disabled',
        ][$status] ?? 'disabled';
    }

    /**
     * Fetch + decode a JSON endpoint. Always returns a result envelope — a down or
     * misbehaving monitor yields ok=false with a message, never an exception.
     *
     * @return array{ok:bool,error:?string,data:?array}
     */
    private function fetchJson(string $path): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'VPN_MONITOR_URL is not set.', 'data' => null];
        }
        $raw = ($this->fetch)($this->baseUrl . $path);
        if ($raw === null || $raw === '') {
            return ['ok' => false, 'error' => 'Monitor is unreachable.', 'data' => null];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'Monitor returned invalid JSON.', 'data' => null];
        }
        return ['ok' => true, 'error' => null, 'data' => $data];
    }

    /** Plain GET with a short timeout. Returns the body, or null on any failure. */
    private function httpGet(string $url): ?string
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\n",
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
