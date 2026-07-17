<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Db;
use PDO;

/**
 * Admin-editable configuration (Settings → Configuration). Backs a curated
 * WHITELIST of operationally-tunable, NON-SECRET keys with the `app_setting`
 * table so an admin can change them from the web console instead of editing
 * .env and redeploying. Values layer under real environment variables and over
 * the .env file (see Config::overrides), and are pushed into Config at bootstrap
 * so both the web app and the CLI reconciler see them.
 *
 * Secrets — Adaxes tokens, service-account passwords, DB / SAML / Google
 * credentials — are deliberately NOT editable here; they stay in .env / the
 * environment. The whitelist is the security boundary: a key absent from it can
 * never be written through this service.
 */
final class SettingsService
{
    private ?PDO $pdo;
    private ?AuditService $audit;

    public function __construct(?PDO $db = null, ?AuditService $audit = null)
    {
        $this->pdo = $db;
        $this->audit = $audit;
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    private function audit(): AuditService
    {
        return $this->audit ??= new AuditService($this->db());
    }

    /**
     * The editable configuration, grouped for the UI. Each field:
     *   key, label, type (bool|int|float|string|enum), help, and for enum: options.
     * Order here is the display order.
     *
     * @var array<int,array{title:string, help:string, fields:list<array<string,mixed>>}>
     */
    public const SCHEMA = [
        [
            'title' => 'Adaxes — direct AD provisioning (writes)',
            'help'  => 'The master switch and safety valves for IDM writing to Active Directory. OFF by default. Tokens/passwords are NOT here — they stay in .env.',
            'fields' => [
                ['key' => 'ADAXES_WRITE_ENABLED', 'label' => 'Writes enabled', 'type' => 'bool', 'help' => 'Master switch. Off = read-only (today). On = create/edit/disable allowed (still dry-run gated during rollout).'],
                ['key' => 'ADAXES_WRITE_MAX_DISABLES_RATIO', 'label' => 'Max disable ratio', 'type' => 'float', 'help' => 'Block a run that would disable more than this share of linked accounts (truncated-feed guard).'],
                ['key' => 'ADAXES_WRITE_DISABLE_GUARD_MIN', 'label' => 'Disable guard minimum', 'type' => 'int', 'help' => 'The ratio guard only applies once at least this many accounts are linked.'],
                ['key' => 'ADAXES_WRITE_MAX_CREATES', 'label' => 'Max creates per run', 'type' => 'int', 'help' => 'Absolute ceiling on net-new account creation per run.'],
                ['key' => 'ADAXES_CREATE_PATH', 'label' => 'Create path', 'type' => 'string', 'help' => 'REST path for create (version-specific).'],
                ['key' => 'ADAXES_MODIFY_PATH', 'label' => 'Modify path', 'type' => 'string', 'help' => 'REST path for attribute modify (version-specific).'],
                ['key' => 'ADAXES_DISABLE_PATH', 'label' => 'Disable path', 'type' => 'string', 'help' => 'Optional operation path; blank = toggle accountDisabled via the modify path.'],
                ['key' => 'ADAXES_ENABLE_PATH', 'label' => 'Enable path', 'type' => 'string', 'help' => 'Optional operation path; blank = toggle accountDisabled via the modify path.'],
                ['key' => 'ADAXES_CREATE_OBJECT_TYPE', 'label' => 'Create object type', 'type' => 'string', 'help' => 'Directory object type sent on create (default "user").'],
            ],
        ],
        [
            'title' => 'Identity minting & OU placement',
            'help'  => 'How IDM mints usernames and where it creates accounts. school.ad_ou (relative building OU) is set per building on the Reference page.',
            'fields' => [
                ['key' => 'AD_EMAIL_DOMAIN', 'label' => 'Email domain', 'type' => 'string', 'help' => 'email = upn = <username>@this.'],
                ['key' => 'AD_UPN_SUFFIX', 'label' => 'UPN suffix', 'type' => 'string', 'help' => 'Usually identical to the email domain.'],
                ['key' => 'AD_BASE_DN', 'label' => 'Base DN', 'type' => 'string', 'help' => 'Domain base appended to the container, e.g. DC=tcs,DC=tusc,DC=k12,DC=al,DC=us. REQUIRED for create.'],
                ['key' => 'AD_PARENT_OU', 'label' => 'Parent OU', 'type' => 'string', 'help' => 'Shared OU every provisioned account nests under (default OU=Faculty).'],
                ['key' => 'AD_OU_CONTRACTOR', 'label' => 'Contractor leaf OU', 'type' => 'string', 'help' => 'Innermost OU for contractors (default OU=PTC).'],
                ['key' => 'AD_OU_SUB', 'label' => 'Sub leaf OU', 'type' => 'string', 'help' => 'Innermost OU for substitutes (default OU=Subs).'],
                ['key' => 'AD_OU_INTERN', 'label' => 'Intern leaf OU', 'type' => 'string', 'help' => 'Innermost OU for interns (default OU=Interns).'],
                ['key' => 'AD_OU_BUS_DRIVER', 'label' => 'Bus Driver OU', 'type' => 'string', 'help' => 'Transportation OU (no building segment) for Bus Driver titles (default OU=trans).'],
                ['key' => 'AD_DEPT_BUS_DRIVER', 'label' => 'Bus Driver department', 'type' => 'string', 'help' => 'AD department override for Bus Drivers (default Transportation).'],
                ['key' => 'AD_OU_SRO', 'label' => 'SRO leaf OU', 'type' => 'string', 'help' => 'Innermost OU above the building for SRO titles (default OU=SRO).'],
            ],
        ],
        [
            'title' => 'AD group membership (Phase 4)',
            'help'  => 'Group NAMES only — the matching rules are fixed in code. Per-person Raptor exceptions are set on each person page. Membership writes require the two endpoints below.',
            'fields' => [
                ['key' => 'AD_GROUP_ALL_FACULTY', 'label' => 'All-Faculty group', 'type' => 'string', 'help' => 'Everyone is a member (default All-Faculty).'],
                ['key' => 'AD_GROUP_TRANSPORTATION', 'label' => 'Transportation group', 'type' => 'string', 'help' => 'Bus drivers (default Transportation).'],
                ['key' => 'AD_GROUP_EVERYONE_SUFFIX', 'label' => 'Everyone group suffix', 'type' => 'string', 'help' => 'Per-school group = <building token><suffix>, e.g. CO + -Everyone (RQES→RQS, UPE→UP baked in).'],
                ['key' => 'AD_GROUP_M365_A1', 'label' => 'M365 A1 license group', 'type' => 'string', 'help' => 'CNP/custodian/bus driver/aide/sub/intern/SRO titles + all contractors.'],
                ['key' => 'AD_GROUP_M365_A3', 'label' => 'M365 A3 license group', 'type' => 'string', 'help' => 'Everyone else.'],
                ['key' => 'AD_GROUP_RAPTOR_BUILDING_ADMIN', 'label' => 'Raptor BuildingAdmin group', 'type' => 'string', 'help' => 'Titles: Principal, IT Computer Tech (default Raptor_BuildingAdmin).'],
                ['key' => 'AD_GROUP_RAPTOR_CLIENT_ADMIN', 'label' => 'Raptor ClientAdmin group', 'type' => 'string', 'help' => 'Titles: IT Technician Supervisor, Safety Contractor, Director of Technology (default Raptor_ClientAdmin).'],
                ['key' => 'AD_GROUP_RAPTOR_ENTRY_ADMIN', 'label' => 'Raptor EntryAdmin group', 'type' => 'string', 'help' => 'Titles: Secretary, bookkeeper (default Raptor_EntryAdmin).'],
                ['key' => 'AD_GROUP_RAPTOR_GLOBAL_ADMIN', 'label' => 'Raptor GlobalAdmin group', 'type' => 'string', 'help' => 'Titles: Network Administrator, Security Specialist (default Raptor_GlobalAdmin).'],
                ['key' => 'AD_GROUP_RAPTOR_DEFAULT', 'label' => 'Raptor default group', 'type' => 'string', 'help' => 'Everyone with no title match (default Raptor_EmergencyManagementUser).'],
                ['key' => 'AD_GROUP_RAPTOR_STUDENT_SAFE', 'label' => 'Raptor StudentSafeUser group', 'type' => 'string', 'help' => 'Additive — granted on top of the Raptor role for titles Principal, Assistant Principal, Social Worker, Counselor (default Raptor_StudentSafeUser).'],
                ['key' => 'ADAXES_GROUP_ADD_PATH', 'label' => 'Group add path', 'type' => 'string', 'help' => 'REST path to add a member; blank = groups phase is report-only.'],
                ['key' => 'ADAXES_GROUP_REMOVE_PATH', 'label' => 'Group remove path', 'type' => 'string', 'help' => 'REST path to remove a member; blank = groups phase is report-only.'],
                ['key' => 'ADAXES_GROUP_PARAM', 'label' => 'Group param name', 'type' => 'string', 'help' => 'Query/body param naming the group (default "group").'],
                ['key' => 'ADAXES_MEMBER_PARAM', 'label' => 'Member param name', 'type' => 'string', 'help' => 'Query/body param naming the member (default "member").'],
            ],
        ],
        [
            'title' => 'Email & rename notifications',
            'help'  => 'How IDM sends mail and the rename/alias timing. The SMTP password stays in .env; set it there, not here.',
            'fields' => [
                ['key' => 'MAIL_ENABLED', 'label' => 'Mail enabled', 'type' => 'bool', 'help' => 'Off = compose + queue but do not deliver.'],
                ['key' => 'MAIL_FROM', 'label' => 'From address', 'type' => 'string', 'help' => 'Envelope/header From.'],
                ['key' => 'MAIL_TRANSPORT', 'label' => 'Transport', 'type' => 'string', 'help' => 'null | sendmail | smtp.'],
                ['key' => 'SMTP_HOST', 'label' => 'SMTP host', 'type' => 'string', 'help' => 'Relay host (smtp transport).'],
                ['key' => 'SMTP_PORT', 'label' => 'SMTP port', 'type' => 'int', 'help' => 'Usually 587 (STARTTLS) or 465 (implicit TLS).'],
                ['key' => 'SMTP_SECURITY', 'label' => 'SMTP security', 'type' => 'string', 'help' => 'none | tls | ssl.'],
                ['key' => 'SMTP_USER', 'label' => 'SMTP username', 'type' => 'string', 'help' => 'Submission username (the password stays in .env).'],
                ['key' => 'IT_NOTIFY_EMAIL', 'label' => 'IT notify address(es)', 'type' => 'string', 'help' => 'Comma-separated IT recipients for rename/alias notices.'],
                ['key' => 'RENAME_NOTICE_DAYS', 'label' => 'Rename notice days', 'type' => 'int', 'help' => 'Days between a rename and the cutover (default 7).'],
                ['key' => 'RENAME_ALIAS_DAYS', 'label' => 'Alias retention days', 'type' => 'int', 'help' => 'How long the old email keeps delivering as an alias (default 90).'],
                ['key' => 'RENAME_ALIAS_REMINDER_DAYS', 'label' => 'Alias reminder days-before', 'type' => 'string', 'help' => 'Comma list of days-before-removal to remind (default 14,3).'],
            ],
        ],
        [
            'title' => 'Adaxes — connection & verification (non-secret)',
            'help'  => 'The read/verify connection. The token and service username/password stay in .env.',
            'fields' => [
                ['key' => 'ADAXES_BASE_URL', 'label' => 'Base URL', 'type' => 'string', 'help' => 'e.g. https://adaxes.example.org/restv2 (blank disables verification).'],
                ['key' => 'ADAXES_TIMEOUT', 'label' => 'Timeout (seconds)', 'type' => 'int', 'help' => 'How long to wait for Adaxes before giving up.'],
                ['key' => 'ADAXES_VERIFY_TLS', 'label' => 'Verify TLS', 'type' => 'bool', 'help' => 'Keep on. Only disable for a self-signed host you cannot otherwise trust.'],
                ['key' => 'ADAXES_EMPLOYEE_ID_ATTR', 'label' => 'Employee ID attribute', 'type' => 'string', 'help' => 'AD attribute holding the employee id (default employeeID).'],
                ['key' => 'ADAXES_DEBUG', 'label' => 'Debug logging', 'type' => 'bool', 'help' => 'Logs request URLs + response snippets (contains PII). Turn off when done.'],
            ],
        ],
        [
            'title' => 'Cutover',
            'help'  => 'Switches for the OneSync → IDM cutover. Turn the OneSync DB sync off once IDM is authoritative for AD/Google so provisioning results stop being pulled from OneSync.',
            'fields' => [
                ['key' => 'ONESYNC_DB_SYNC_ENABLED', 'label' => 'OneSync DB sync enabled', 'type' => 'bool', 'help' => 'On (default) = pull provisioning results from OneSync. Off = cutover — IDM is authoritative; the sync is skipped.'],
            ],
        ],
    ];

    /** @return list<string> every whitelisted key (the write boundary). */
    public static function whitelist(): array
    {
        $keys = [];
        foreach (self::SCHEMA as $group) {
            foreach ($group['fields'] as $f) {
                $keys[] = (string) $f['key'];
            }
        }
        return $keys;
    }

    /** The field definition for a key, or null if it isn't whitelisted. */
    public static function field(string $key): ?array
    {
        foreach (self::SCHEMA as $group) {
            foreach ($group['fields'] as $f) {
                if ($f['key'] === $key) {
                    return $f;
                }
            }
        }
        return null;
    }

    /**
     * Stored override values (raw strings) for whitelisted keys, keyed by key.
     * Keys with no row are absent.
     *
     * @return array<string,string>
     */
    public function stored(): array
    {
        $rows = $this->db()->query('SELECT setting_key, setting_value FROM app_setting')->fetchAll();
        $out = [];
        $white = array_flip(self::whitelist());
        foreach ($rows as $r) {
            $k = (string) $r['setting_key'];
            if (isset($white[$k])) {
                $out[$k] = (string) ($r['setting_value'] ?? '');
            }
        }
        return $out;
    }

    /**
     * Push the stored overrides into Config so the running process (web or CLI)
     * uses them. Best-effort: swallows any error (missing table / DB down / no
     * DB configured) so a fresh checkout, a migration run, or the test suite
     * boots normally with just .env.
     */
    public static function applyOverridesSafe(): void
    {
        try {
            Config::overrides((new self())->stored());
        } catch (\Throwable) {
            // No DB / no table yet — .env + defaults stand.
        }
    }

    /**
     * Apply submitted values (only whitelisted keys are honored). A bool always
     * stores an explicit true/false; a blank string on any other type deletes the
     * override (reverting to .env/default). Each change is audited. Returns the
     * count of keys changed.
     *
     * @param array<string,mixed> $input raw form input (key => value)
     */
    public function save(array $input, string $actor): int
    {
        $current = $this->stored();
        $changed = 0;

        foreach (self::SCHEMA as $group) {
            foreach ($group['fields'] as $f) {
                $key = (string) $f['key'];
                // An env-locked key can't be changed here — skip silently.
                if (Config::isEnvLocked($key)) {
                    continue;
                }
                [$store, $delete] = self::normalize($f, $input[$key] ?? null, array_key_exists($key, $input));
                $existing = $current[$key] ?? null;

                if ($delete) {
                    if ($existing !== null) {
                        $this->db()->prepare('DELETE FROM app_setting WHERE setting_key = :k')->execute([':k' => $key]);
                        $this->audit()->log('config', null, 'update', [$key => $existing], [$key => null], $actor);
                        $changed++;
                    }
                    continue;
                }
                if ($existing === $store) {
                    continue; // no change
                }
                $this->upsert($key, $store, $actor);
                $this->audit()->log('config', null, 'update', [$key => $existing], [$key => $store], $actor);
                $changed++;
            }
        }

        if ($changed > 0) {
            Config::overrides($this->stored()); // reflect immediately in this request
        }
        return $changed;
    }

    /**
     * Set a single whitelisted bool setting (true/false) — for one-click toggles
     * like the OneSync cutover switch, without touching any other key (unlike
     * save(), which reconciles every field in the schema). Audited; the override
     * layer is refreshed so the change takes effect this request. No-op if
     * unchanged; throws if the key isn't a whitelisted bool or is env-locked.
     */
    public function setBool(string $key, bool $value, string $actor): void
    {
        $field = self::field($key);
        if ($field === null || ($field['type'] ?? '') !== 'bool') {
            throw new \InvalidArgumentException("Not a whitelisted bool setting: {$key}");
        }
        if (Config::isEnvLocked($key)) {
            throw new \RuntimeException("{$key} is set in .env and cannot be changed here.");
        }
        $store = $value ? 'true' : 'false';
        $existing = $this->stored()[$key] ?? null;
        if ($existing === $store) {
            return;
        }
        $this->upsert($key, $store, $actor);
        $this->audit()->log('config', null, 'update', [$key => $existing], [$key => $store], $actor);
        Config::overrides($this->stored());
    }

    private function upsert(string $key, string $value, string $actor): void
    {
        // Portable upsert (select → insert/update) so it runs on MySQL and sqlite.
        $exists = $this->db()->prepare('SELECT 1 FROM app_setting WHERE setting_key = :k');
        $exists->execute([':k' => $key]);
        if ($exists->fetchColumn() !== false) {
            $this->db()->prepare('UPDATE app_setting SET setting_value = :v, updated_by = :by WHERE setting_key = :k')
                ->execute([':v' => $value, ':by' => $actor, ':k' => $key]);
        } else {
            $this->db()->prepare('INSERT INTO app_setting (setting_key, setting_value, updated_by) VALUES (:k, :v, :by)')
                ->execute([':k' => $key, ':v' => $value, ':by' => $actor]);
        }
    }

    /**
     * Coerce a submitted value per its field type into the value to store.
     *
     * @param array<string,mixed> $field
     * @return array{0:string,1:bool} [value-to-store, should-delete]
     */
    private static function normalize(array $field, mixed $raw, bool $present): array
    {
        $type = (string) ($field['type'] ?? 'string');

        if ($type === 'bool') {
            // A checkbox is absent when unchecked → explicit false (never delete).
            $on = $present && in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
            return [$on ? 'true' : 'false', false];
        }

        $val = trim((string) ($raw ?? ''));
        if ($val === '') {
            return ['', true]; // blank → revert to .env/default
        }
        if ($type === 'int') {
            return [(string) (int) $val, false];
        }
        if ($type === 'float') {
            return [(string) (float) $val, false];
        }
        return [$val, false];
    }
}
