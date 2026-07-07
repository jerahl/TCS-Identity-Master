# MCP Server for Claude

TCS Identity Master ships a built-in **MCP (Model Context Protocol) server** so
Claude can query — and, for the right roles, act on — the identity system
directly. It's a remote MCP server over the **Streamable-HTTP** transport at:

```
POST https://<host>/mcp
```

Two ideas drive the design:

- **The API key is per user.** Every user mints their own key (there's no shared
  secret). A key *acts as that user*.
- **The user's app role decides what Claude can do.** The same RBAC matrix the
  web app uses (`readonly` < `editor` < `admin`) gates which MCP tools are even
  *visible*, and re-checks on every call. Claude can never do more through MCP
  than the key's owner could do in the UI.

Because the tools reuse the app's existing services, every guardrail still
applies: writes are audited and attributed to the key owner, username locks hold,
and DB least-privilege roles are unchanged.

---

## Authentication

Send your personal key on every request as either:

```
Authorization: Bearer <your-key>
```
or
```
X-API-Key: <your-key>
```

- Keys look like `tcsidm_` followed by 40 hex characters.
- Only a SHA-256 hash of the key is stored — the raw value is shown **once** at
  creation and can't be retrieved later (only revoked).
- A missing/invalid/revoked key, or a deactivated owner, returns `401`.
- The role is resolved **live** on each request, so a role change or revocation
  takes effect immediately — no need to re-issue keys.
- Set `MCP_ENABLED=false` to disable the endpoint entirely (returns `503`).

### Getting a key

**Web (self-service):** sign in → **Settings ▸ API keys** → *Create a key*.
Copy it immediately.

**CLI (admin/ops):**

```bash
php bin/api_key.php create --email=you@tuscaloosacityschools.com --label="Claude Desktop"
php bin/api_key.php list   --email=you@tuscaloosacityschools.com
php bin/api_key.php revoke --id=42
```

A key's rights follow the owner's role — change it with `bin/set_role.php`.

---

## Tools & required role

`tools/list` only returns the tools your role can use; `tools/call` re-checks.

| Tool                 | Min role  | What it does |
|----------------------|-----------|--------------|
| `whoami`             | any       | Who the key belongs to + which capabilities the role grants. |
| `search_people`      | readonly  | Search golden records by name/username/email/ID/UUID (+ status/type filters). |
| `get_person`         | readonly  | Full person detail: record, source-ID crosswalk, assignments, sync status, timeline. |
| `dashboard_summary`  | readonly  | KPIs + OneSync write-back freshness + student-sync status. |
| `list_failed_syncs`  | readonly  | Accounts whose last provisioning attempt failed. |
| `list_review_queue`  | readonly  | Pending match-review cases. |
| `confirm_match`      | editor    | Confirm a pending match (link staged record → person). Audited. |
| `reject_match`       | editor    | Reject a pending match case. Audited. |
| `list_users`         | admin     | List application users and their roles. |

So a **readonly** key gets read-only tools, an **editor** key additionally works
the review queue, and an **admin** key sees everything.

---

## Connecting Claude

### Claude Code (CLI)

```bash
claude mcp add --transport http tcs-identity https://<host>/mcp \
  --header "Authorization: Bearer <your-key>"
```

### Claude Desktop / claude.ai (custom connector)

Add a custom connector pointing at `https://<host>/mcp` and configure the
`Authorization: Bearer <your-key>` header. Then ask Claude things like
*"search TCS Identity for people missing a username"* or *"show the review
queue."*

### Claude Desktop (config file / `mcp-remote`)

If your Claude Desktop build doesn't offer a custom-header field for
connectors, bridge the connection with [`mcp-remote`](https://www.npmjs.com/package/mcp-remote)
instead (requires Node.js). Edit `claude_desktop_config.json`:

- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "tcs-identity": {
      "command": "cmd",
      "args": [
        "/c",
        "npx",
        "mcp-remote",
        "https://<host>/mcp",
        "--header",
        "Authorization:${AUTH_HEADER}"
      ],
      "env": {
        "AUTH_HEADER": "Bearer <your-key>"
      }
    }
  }
}
```

On macOS/Linux, drop the `cmd`/`/c` wrapper and use `"command": "npx"` with
the remaining args.

Two Windows-specific gotchas this config works around (both are known Claude
Desktop bugs, documented in the mcp-remote README):

- **Unquoted `npx` path.** Claude Desktop resolves `npx` to
  `C:\Program Files\nodejs\npx.cmd` and runs it through `cmd /C` without
  quotes, so the launch dies with `'C:\Program' is not recognized …` and the
  log shows *"Server transport closed unexpectedly"*. Making the command
  `cmd` itself and passing `npx` as an argument lets cmd resolve it via
  `PATH`, so no space-containing path is ever built.
- **Args split on spaces.** Argument values containing spaces get split too,
  which mangles a header like `Authorization: Bearer …`. Keeping the header
  argument space-free (`Authorization:${AUTH_HEADER}`) and putting
  `Bearer <your-key>` in the env var avoids it — mcp-remote substitutes
  `${VAR}` from its environment.

After editing the file, **fully quit** Claude Desktop (File ▸ Exit or
tray-icon ▸ Quit — closing the window isn't enough) and relaunch. To verify
end-to-end, ask Claude to run the `whoami` tool. If the MCP log then shows a
`401`, the transport is fine and the server rejected the key — re-check that
it was pasted whole and hasn't been revoked.

---

## Protocol notes

- JSON-RPC 2.0. Implemented methods: `initialize`, `notifications/*` (accepted,
  no reply → `202`), `ping`, `tools/list`, `tools/call`.
- Protocol version: `2025-06-18`.
- Responses are plain `application/json` (no SSE) — sufficient for these simple
  request/response tools.
- Tool results carry both a human-readable `text` block and a
  `structuredContent` object.
- Tool-level problems (bad arguments, not found) come back as a tool result with
  `isError: true`; transport/protocol problems come back as JSON-RPC errors
  (`-32601` for an unknown/again disallowed tool, etc.).

---

## Security

- Per-user keys mean actions are attributable to a person (audited as
  `mcp:<email>`), and one key can be revoked without affecting anyone else.
- A key can never exceed its owner's role — privilege is checked server-side on
  every call, not just hidden from the tool list.
- Rotate keys by creating a new one and revoking the old. Revocation is instant.
- Serve `/mcp` over HTTPS only (the app enforces HTTPS in production).
