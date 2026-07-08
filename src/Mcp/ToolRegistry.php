<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Db;
use App\Service\AuthService;
use App\Service\DashboardService;
use App\Service\PersonService;
use App\Service\ReviewService;

/**
 * The set of MCP tools and the capability each one requires. This is where the
 * app's RBAC becomes Claude's RBAC: a tool declares a required capability
 * ('view' | 'edit' | 'admin', or null for any authenticated key) and the caller's
 * role is checked against the SAME AuthService matrix the web routes use.
 *
 *   readonly -> view tools only        (search / read people, dashboard)
 *   editor   -> view + edit tools      (+ work the match review queue)
 *   admin    -> everything             (+ inspect users & keys)
 *
 * tools/list only advertises tools the role may use, and tools/call re-checks —
 * so a lower-privilege key never sees, nor can invoke, a higher-privilege tool.
 *
 * Each tool entry: [description, capability, inputSchema, handler]. Handlers take
 * the decoded `arguments` array and return a JSON-serialisable array.
 */
final class ToolRegistry
{
    /** @var array<string, array{description:string, capability:?string, inputSchema:array, handler:callable}> */
    private array $tools;

    /** @param array<string, array{description:string, capability:?string, inputSchema:array, handler:callable}> $tools */
    public function __construct(array $tools)
    {
        $this->tools = $tools;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /** Required capability for a tool (null = any authenticated key). */
    public function capabilityOf(string $name): ?string
    {
        return $this->tools[$name]['capability'] ?? null;
    }

    /** Whether a role may use a tool (null capability is always allowed). */
    public function allows(string $role, string $name): bool
    {
        if (!$this->has($name)) {
            return false;
        }
        $cap = $this->tools[$name]['capability'];
        return $cap === null || AuthService::roleHasCapability($role, $cap);
    }

    /**
     * The tool specs (name/description/inputSchema — no handler) a role may see,
     * i.e. the payload for tools/list.
     *
     * @return array<int, array{name:string, description:string, inputSchema:array}>
     */
    public function specsFor(string $role): array
    {
        $out = [];
        foreach ($this->tools as $name => $def) {
            if (!$this->allows($role, $name)) {
                continue;
            }
            $out[] = [
                'name'        => $name,
                'description' => $def['description'],
                'inputSchema' => $def['inputSchema'],
            ];
        }
        return $out;
    }

    /**
     * Run a tool. Caller must have already checked allows(); this executes the
     * handler and returns its array result.
     *
     * @param array<string,mixed> $arguments
     */
    public function call(string $name, array $arguments): array
    {
        return ($this->tools[$name]['handler'])($arguments);
    }

    /**
     * Wire the real tool set for a request context. Handlers reuse the existing
     * read/write services, so every guardrail (username lock, audit, DB roles)
     * still applies exactly as it does in the web app.
     */
    public static function default(McpContext $ctx): self
    {
        $people = new PersonService();
        $dashboard = new DashboardService();
        $review = new ReviewService();

        return new self([

            // ---- Any authenticated key -----------------------------------------
            'whoami' => [
                'capability'  => null,
                'description' => 'Return the identity and role of the user this API key belongs to, '
                    . 'plus which capabilities (view/edit/admin) that role grants.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler'     => static fn(array $a): array => [
                    'user_id'      => $ctx->userId,
                    'email'        => $ctx->email,
                    'display_name' => $ctx->displayName,
                    'role'         => $ctx->role,
                    'capabilities' => [
                        'view'  => AuthService::roleHasCapability($ctx->role, 'view'),
                        'edit'  => AuthService::roleHasCapability($ctx->role, 'edit'),
                        'admin' => AuthService::roleHasCapability($ctx->role, 'admin'),
                    ],
                ],
            ],

            // ---- View (readonly and up) ----------------------------------------
            'search_people' => [
                'capability'  => 'view',
                'description' => 'Search the identity golden records by name, username, email, employee ID '
                    . 'or UUID, optionally filtered by status/type. Returns matching people (summary fields).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query'  => ['type' => 'string', 'description' => 'Free-text search across name, username, email, employee_id, uuid.'],
                        'status' => ['type' => 'string', 'enum' => ['pending', 'active', 'disabled', 'terminated'], 'description' => 'Filter by lifecycle status.'],
                        'type'   => ['type' => 'string', 'enum' => ['faculty', 'staff', 'contractor', 'sub', 'intern', 'other'], 'description' => 'Filter by person type.'],
                        'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max rows (default 25).'],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => static function (array $a) use ($people): array {
                    $limit = self::clampLimit($a['limit'] ?? null, 25, 100);
                    $result = $people->list([
                        'q'      => (string) ($a['query'] ?? ''),
                        'status' => (string) ($a['status'] ?? 'all'),
                        'type'   => (string) ($a['type'] ?? 'all'),
                    ]);
                    $rows = array_slice($result['rows'], 0, $limit);
                    return [
                        'total_people' => $result['total'],
                        'returned'     => count($rows),
                        'limit'        => $limit,
                        'people'       => $rows,
                    ];
                },
            ],

            'get_person' => [
                'capability'  => 'view',
                'description' => 'Full detail for one person by numeric person_id or UUID: golden record, '
                    . 'source-ID crosswalk, assignments, per-destination provisioning status and lifecycle timeline.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'person_id' => ['type' => 'integer', 'description' => 'Numeric person_id.'],
                        'uuid'      => ['type' => 'string', 'description' => 'person_uuid (OneSync uniqueId).'],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => static function (array $a) use ($people): array {
                    $id = self::resolvePersonId($a);
                    if ($id === null) {
                        throw new McpToolException('Provide either person_id or uuid.');
                    }
                    $person = $people->find($id);
                    if ($person === null) {
                        throw new McpToolException('No person found for the given id/uuid.');
                    }
                    return [
                        'person'      => $person,
                        'source_ids'  => $people->sourceIds($id),
                        'assignments' => $people->assignments($id),
                        'sync_status' => $people->syncStatus($id),
                        'timeline'    => $people->timeline($id, 25),
                    ];
                },
            ],

            'dashboard_summary' => [
                'capability'  => 'view',
                'description' => 'Operational KPIs (pending review/activation, missing usernames, unmapped '
                    . 'reference data, failed syncs) plus OneSync DB sync freshness.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => static fn(array $a): array => [
                    'kpis'        => $dashboard->kpis(),
                    'sync_health' => $dashboard->syncHealth(),
                    'students'    => $dashboard->studentSync(),
                ],
            ],

            'list_failed_syncs' => [
                'capability'  => 'view',
                'description' => 'Accounts whose most recent OneSync provisioning attempt failed '
                    . '(destination, person, last error, when).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Max rows (default 25).'],
                    ],
                    'additionalProperties' => false,
                ],
                'handler' => static fn(array $a): array => [
                    'failed_syncs' => $dashboard->failedSyncs(self::clampLimit($a['limit'] ?? null, 25, 100)),
                ],
            ],

            'list_review_queue' => [
                'capability'  => 'view',
                'description' => 'Pending match-review cases: incoming staged records that need a human to '
                    . 'confirm or reject a link to an existing person.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => static fn(array $a): array => [
                    'pending_count' => $review->pendingCount(),
                    'cases'         => $review->pendingCases(),
                ],
            ],

            // ---- Edit (editor and up) ------------------------------------------
            'confirm_match' => [
                'capability'  => 'edit',
                'description' => 'Confirm a pending match: link the staged record to the given candidate person. '
                    . 'Audited and attributed to the API key owner.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'staging_id'          => ['type' => 'integer', 'description' => 'The staging_id of the review case.'],
                        'candidate_person_id' => ['type' => 'integer', 'description' => 'The person_id to link the record to.'],
                    ],
                    'required' => ['staging_id', 'candidate_person_id'],
                    'additionalProperties' => false,
                ],
                'handler' => static function (array $a) use ($review, $ctx): array {
                    $staging = (int) ($a['staging_id'] ?? 0);
                    $candidate = (int) ($a['candidate_person_id'] ?? 0);
                    if ($staging <= 0 || $candidate <= 0) {
                        throw new McpToolException('staging_id and candidate_person_id are required positive integers.');
                    }
                    $outcome = $review->confirm($staging, $candidate, $ctx->actor());
                    return ['outcome' => $outcome, 'staging_id' => $staging, 'candidate_person_id' => $candidate];
                },
            ],

            'reject_match' => [
                'capability'  => 'edit',
                'description' => 'Reject a pending match case (no link is made). Audited and attributed to the API key owner.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'staging_id' => ['type' => 'integer', 'description' => 'The staging_id of the review case.'],
                    ],
                    'required' => ['staging_id'],
                    'additionalProperties' => false,
                ],
                'handler' => static function (array $a) use ($review, $ctx): array {
                    $staging = (int) ($a['staging_id'] ?? 0);
                    if ($staging <= 0) {
                        throw new McpToolException('staging_id is required and must be a positive integer.');
                    }
                    $outcome = $review->reject($staging, $ctx->actor());
                    return ['outcome' => $outcome, 'staging_id' => $staging];
                },
            ],

            // ---- Admin only ----------------------------------------------------
            'list_users' => [
                'capability'  => 'admin',
                'description' => 'List application users and their roles (admin oversight).',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'handler' => static fn(array $a): array => [
                    'users' => Db::connect(Db::ROLE_APP)->query(
                        'SELECT user_id, email, display_name, role, is_active, last_login_at, created_at
                           FROM app_user ORDER BY role, email'
                    )->fetchAll(),
                ],
            ],
        ]);
    }

    /** Clamp a caller-supplied limit into [1, max] with a default. */
    private static function clampLimit(mixed $raw, int $default, int $max): int
    {
        $n = is_numeric($raw) ? (int) $raw : $default;
        return max(1, min($max, $n));
    }

    /** Resolve person_id from {person_id} or {uuid}; null when neither is usable. */
    private static function resolvePersonId(array $a): ?int
    {
        if (isset($a['person_id']) && (int) $a['person_id'] > 0) {
            return (int) $a['person_id'];
        }
        $uuid = trim((string) ($a['uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        $stmt = Db::connect(Db::ROLE_APP)->prepare('SELECT person_id FROM person WHERE person_uuid = :u');
        $stmt->execute([':u' => $uuid]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }
}
