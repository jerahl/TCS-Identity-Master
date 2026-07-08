<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Minimal MCP (Model Context Protocol) server over JSON-RPC 2.0. The HTTP
 * transport (Streamable HTTP) is handled by McpController; this class is pure —
 * it takes a decoded JSON-RPC message and the caller's role, and returns the
 * decoded response (or null for a notification, which has no reply).
 *
 * Supported methods: initialize, notifications/*, ping, tools/list, tools/call.
 * Role gating lives entirely in ToolRegistry, so a key only ever sees and calls
 * the tools its owner's role permits.
 */
final class McpServer
{
    /** Protocol revision we implement. */
    public const PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly string $role,
        private readonly array $serverInfo = ['name' => 'tcs-identity-master', 'version' => '1.0.0'],
    ) {
    }

    /**
     * Handle one JSON-RPC request object. Returns the response object, or null
     * when the message is a notification (no `id`) and needs no reply.
     *
     * @param array<string,mixed> $req
     * @return array<string,mixed>|null
     */
    public function handle(array $req): ?array
    {
        $isNotification = !array_key_exists('id', $req);
        $id = $req['id'] ?? null;
        $method = (string) ($req['method'] ?? '');
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];

        // Notifications (e.g. notifications/initialized) get no response at all.
        if ($isNotification) {
            return null;
        }

        if (($req['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid Request');
        }

        return match ($method) {
            'initialize'   => self::result($id, $this->initialize()),
            'ping'         => self::result($id, (object) []),
            'tools/list'   => self::result($id, ['tools' => $this->tools->specsFor($this->role)]),
            'tools/call'   => $this->toolsCall($id, $params),
            default        => self::error($id, -32601, "Method not found: {$method}"),
        };
    }

    /** @return array<string,mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => $this->serverInfo,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function toolsCall(mixed $id, array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        // Hide existence of tools the role can't use: unknown OR disallowed both
        // read as "not available", so a lower role can't probe higher-role tools.
        if (!$this->tools->allows($this->role, $name)) {
            return self::error($id, -32601, "Unknown tool or insufficient role: {$name}");
        }

        try {
            $data = $this->tools->call($name, $args);
            return self::result($id, self::toolContent($data, false));
        } catch (McpToolException $e) {
            // Expected, client-facing failure -> tool result with isError.
            return self::result($id, self::toolContent(['error' => $e->getMessage()], true));
        } catch (\Throwable $e) {
            error_log('[idm][mcp] tool ' . $name . ': ' . $e->getMessage());
            return self::error($id, -32603, 'Internal error running tool.');
        }
    }

    /**
     * Wrap a handler's data as MCP tool-call content. The structured value goes in
     * `structuredContent`; a JSON rendering goes in a text block for clients that
     * only read text.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function toolContent(array $data, bool $isError): array
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return [
            'content'           => [['type' => 'text', 'text' => $json === false ? '{}' : $json]],
            'structuredContent' => $data,
            'isError'           => $isError,
        ];
    }

    /** @return array<string,mixed> */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string,mixed> */
    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
