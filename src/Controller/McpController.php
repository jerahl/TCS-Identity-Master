<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Mcp\McpContext;
use App\Mcp\McpServer;
use App\Mcp\ToolRegistry;
use App\Service\ApiKeyService;

/**
 * MCP endpoint for Claude (and other MCP clients): a remote server over the
 * Streamable-HTTP transport at POST /mcp. Not session/CSRF based — it
 * authenticates with a PER-USER API key (Authorization: Bearer <key>, or
 * X-API-Key). The key resolves to its owning app_user, and that user's LIVE role
 * decides which tools Claude may list and call (see ToolRegistry).
 *
 * Responses are plain application/json (a valid Streamable-HTTP reply for simple
 * request/response tools — no SSE needed). Disable entirely with MCP_ENABLED=false.
 */
final class McpController
{
    public function handle(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!Config::bool('MCP_ENABLED', true)) {
            return $this->fail(503, 'MCP endpoint is disabled (set MCP_ENABLED=true to enable).');
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            // GET/DELETE are used by the stateful SSE variant we don't implement.
            header('Allow: POST');
            return $this->fail(405, 'Use POST with a JSON-RPC body.');
        }

        $ctx = $this->authenticate();
        if ($ctx === null) {
            header('WWW-Authenticate: Bearer realm="TCS Identity Master MCP"');
            return $this->fail(401, 'Missing or invalid API key.');
        }

        $raw = file_get_contents('php://input');
        $raw = $raw === false ? '' : $raw;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            http_response_code(400);
            return $this->jsonRpcError(null, -32700, 'Parse error: body is not valid JSON.');
        }

        $server = new McpServer(ToolRegistry::default($ctx), $ctx->role);

        // A JSON-RPC message is a single object; tolerate an array of messages too.
        if (array_is_list($decoded)) {
            $responses = [];
            foreach ($decoded as $one) {
                $resp = is_array($one) ? $server->handle($one) : null;
                if ($resp !== null) {
                    $responses[] = $resp;
                }
            }
            if ($responses === []) {
                http_response_code(202); // all notifications — nothing to return
                return '';
            }
            return $this->encode($responses);
        }

        $response = $server->handle($decoded);
        if ($response === null) {
            http_response_code(202); // notification — accepted, no body
            return '';
        }
        return $this->encode($response);
    }

    /** Resolve the presented API key to a caller context, or null. */
    private function authenticate(): ?McpContext
    {
        $token = $this->presentedToken();
        if ($token === null || $token === '') {
            return null;
        }
        $user = (new ApiKeyService())->verify($token);
        if ($user === null) {
            return null;
        }
        return new McpContext(
            $user['user_id'],
            $user['email'],
            $user['display_name'],
            $user['role'],
        );
    }

    /** Bearer token from Authorization, or X-API-Key fallback. */
    private function presentedToken(): ?string
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        $key = $_SERVER['HTTP_X_API_KEY'] ?? null;
        return $key !== null ? trim((string) $key) : null;
    }

    /** @param array<string,mixed> $payload */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /** Transport-level failure (not a JSON-RPC error): set the HTTP status + body. */
    private function fail(int $status, string $message): string
    {
        http_response_code($status);
        return $this->encode(['ok' => false, 'error' => $message]);
    }

    private function jsonRpcError(mixed $id, int $code, string $message): string
    {
        return $this->encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
