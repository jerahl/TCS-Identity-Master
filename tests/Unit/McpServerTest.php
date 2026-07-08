<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mcp\McpServer;
use App\Mcp\McpToolException;
use App\Mcp\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * MCP protocol handling + the RBAC gate that maps the API-key owner's role onto
 * which tools Claude may list and call. A lower role must neither see nor be able
 * to invoke a higher-role tool.
 */
final class McpServerTest extends TestCase
{
    private function registry(): ToolRegistry
    {
        return new ToolRegistry([
            'always'   => ['capability' => null,    'description' => 'any', 'inputSchema' => ['type' => 'object'], 'handler' => static fn(array $a) => ['ok' => true]],
            'read'     => ['capability' => 'view',  'description' => 'view', 'inputSchema' => ['type' => 'object'], 'handler' => static fn(array $a) => ['read' => true]],
            'write'    => ['capability' => 'edit',  'description' => 'edit', 'inputSchema' => ['type' => 'object'], 'handler' => static fn(array $a) => ['wrote' => $a]],
            'manage'   => ['capability' => 'admin', 'description' => 'admin', 'inputSchema' => ['type' => 'object'], 'handler' => static fn(array $a) => ['managed' => true]],
            'boom'     => ['capability' => 'view',  'description' => 'boom', 'inputSchema' => ['type' => 'object'], 'handler' => static fn(array $a) => throw new McpToolException('bad input')],
        ]);
    }

    private function server(string $role): McpServer
    {
        return new McpServer($this->registry(), $role);
    }

    private function toolNames(array $listResult): array
    {
        return array_map(static fn($t) => $t['name'], $listResult['result']['tools']);
    }

    public function testInitializeReportsProtocolAndServerInfo(): void
    {
        $resp = $this->server('readonly')->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        self::assertSame(McpServer::PROTOCOL_VERSION, $resp['result']['protocolVersion']);
        self::assertArrayHasKey('tools', $resp['result']['capabilities']);
        self::assertSame('tcs-identity-master', $resp['result']['serverInfo']['name']);
    }

    public function testNotificationGetsNoResponse(): void
    {
        $resp = $this->server('admin')->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        self::assertNull($resp);
    }

    public function testPing(): void
    {
        $resp = $this->server('readonly')->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping']);
        self::assertSame([], (array) $resp['result']);
        self::assertSame(9, $resp['id']);
    }

    public function testToolsListIsFilteredByRole(): void
    {
        $readonly = $this->toolNames($this->server('readonly')->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        self::assertEqualsCanonicalizing(['always', 'read', 'boom'], $readonly);

        $editor = $this->toolNames($this->server('editor')->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        self::assertEqualsCanonicalizing(['always', 'read', 'boom', 'write'], $editor);

        $admin = $this->toolNames($this->server('admin')->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
        self::assertEqualsCanonicalizing(['always', 'read', 'boom', 'write', 'manage'], $admin);
    }

    public function testReadonlyCannotCallEditTool(): void
    {
        $resp = $this->server('readonly')->handle([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'write', 'arguments' => []],
        ]);
        self::assertArrayHasKey('error', $resp);
        self::assertSame(-32601, $resp['error']['code']);
    }

    public function testReadonlyCannotCallAdminTool(): void
    {
        $resp = $this->server('readonly')->handle([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'manage', 'arguments' => []],
        ]);
        self::assertArrayHasKey('error', $resp);
        self::assertSame(-32601, $resp['error']['code']);
    }

    public function testEditorCanCallEditTool(): void
    {
        $resp = $this->server('editor')->handle([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'write', 'arguments' => ['x' => 1]],
        ]);
        self::assertArrayNotHasKey('error', $resp);
        self::assertFalse($resp['result']['isError']);
        self::assertSame(['wrote' => ['x' => 1]], $resp['result']['structuredContent']);
    }

    public function testAnyAuthenticatedRoleCanCallNullCapabilityTool(): void
    {
        $resp = $this->server('readonly')->handle([
            'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
            'params' => ['name' => 'always', 'arguments' => []],
        ]);
        self::assertSame(['ok' => true], $resp['result']['structuredContent']);
    }

    public function testToolExceptionBecomesIsErrorResult(): void
    {
        $resp = $this->server('readonly')->handle([
            'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
            'params' => ['name' => 'boom', 'arguments' => []],
        ]);
        self::assertArrayNotHasKey('error', $resp); // it's a tool result, not a transport error
        self::assertTrue($resp['result']['isError']);
        self::assertSame(['error' => 'bad input'], $resp['result']['structuredContent']);
    }

    public function testUnknownToolIsMethodNotFound(): void
    {
        $resp = $this->server('admin')->handle([
            'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
            'params' => ['name' => 'nope', 'arguments' => []],
        ]);
        self::assertSame(-32601, $resp['error']['code']);
    }

    public function testUnknownMethod(): void
    {
        $resp = $this->server('admin')->handle(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'frobnicate']);
        self::assertSame(-32601, $resp['error']['code']);
    }
}
