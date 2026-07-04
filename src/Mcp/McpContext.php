<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * The authenticated caller behind an MCP request: which app_user the API key
 * belongs to and their live role. Passed to every tool handler so mutations are
 * attributed to the real person (via actor()) and so tools can report identity.
 */
final class McpContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
        public readonly ?string $displayName,
        public readonly string $role,
    ) {
    }

    /**
     * Actor string recorded on audited writes. Kept within the 60-char audit_log
     * column and tagged so MCP-originated changes are distinguishable from the UI.
     */
    public function actor(): string
    {
        return mb_substr('mcp:' . $this->email, 0, 60);
    }
}
