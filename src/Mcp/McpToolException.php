<?php

declare(strict_types=1);

namespace App\Mcp;

use RuntimeException;

/**
 * A tool-level failure that is safe to report back to the caller (bad arguments,
 * not-found, etc.). The server turns it into an MCP tool result with isError:true
 * rather than a transport error, and its message is client-facing — so never put
 * secrets or internal detail here.
 */
final class McpToolException extends RuntimeException
{
}
