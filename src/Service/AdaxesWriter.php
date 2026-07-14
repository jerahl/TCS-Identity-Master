<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * WRITE client for the Adaxes REST API — the direct AD provisioner that lets IDM
 * create / modify / disable Active Directory accounts itself, bypassing OneSync
 * (see docs/adaxes-provisioning-design.md). It is the write counterpart to the
 * read-only AdaxesService and shares its auth + transport (the AdaxesHttp trait):
 * same token/handshake authentication, same TLS/CA handling, same injectable
 * $fetch for tests, same never-throw contract — every method returns a result
 * envelope.
 *
 * Off by default: configured() is false unless ADAXES_WRITE_ENABLED=true AND a
 * base URL and a write credential are present. The write service account is
 * separate from the read-only one (least privilege) — ADAXES_WRITE_TOKEN, falling
 * back to ADAXES_TOKEN, then the ADAXES_USERNAME/ADAXES_PASSWORD handshake.
 *
 * Everything OPERATIONAL about a new account (home directory, group membership,
 * licensing) is left to Adaxes Business Rules that fire on the create — IDM sends
 * only the identity core. The initial password is the exception: Business Rules
 * do NOT fire on REST API events, so IDM sets it itself via resetPassword() right
 * after the create (see AdaxesReconciler), rather than relying on a create rule.
 *
 * @phpstan-type CreateResult array{ok:bool, error:?string, guid:?string, created:bool}
 * @phpstan-type ModifyResult array{ok:bool, error:?string, changed:array<string,string>}
 * @phpstan-type ToggleResult array{ok:bool, error:?string, changed:bool}
 */
final class AdaxesWriter
{
    /** Shared Adaxes auth + HTTP transport. */
    use AdaxesHttp;

    private bool $writeEnabled;
    private string $objectsPath;
    private string $objectParam;
    private string $createPath;
    private string $modifyPath;
    private string $disablePath;
    private string $enablePath;
    private string $groupMembersPath;
    private string $movePath;
    private string $moveObjectField;
    private string $moveDestField;
    private string $createObjectType;
    private string $resetPasswordPath;

    /**
     * @param callable(string,string,array<string,string>,?string):?array{status:int,body:string}|null $fetch
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null,
        ?int $timeout = null,
        ?callable $fetch = null,
        ?string $token = null,
        ?bool $writeEnabled = null,
    ) {
        $this->baseUrl  = rtrim($baseUrl ?? (string) Config::get('ADAXES_BASE_URL', ''), '/');
        $this->username = $username ?? (string) Config::get('ADAXES_USERNAME', '');
        $this->password = $password ?? (string) Config::get('ADAXES_PASSWORD', '');
        // Prefer the dedicated write token; fall back to the shared read token so a
        // single-account deployment still works. Empty → the handshake is used.
        $this->token    = trim($token ?? (string) (Config::get('ADAXES_WRITE_TOKEN', '') ?: Config::get('ADAXES_TOKEN', '')));
        $this->timeout  = $timeout ?? max(1, (int) Config::get('ADAXES_TIMEOUT', '5'));

        $this->writeEnabled = $writeEnabled ?? Config::bool('ADAXES_WRITE_ENABLED', false);

        // Endpoints are version-specific — confirm against the deployed Adaxes
        // build, exactly like the read paths. Sensible 2025.x defaults shown.
        $this->objectsPath      = trim((string) Config::get('ADAXES_OBJECTS_PATH', 'api/directoryObjects'), '/');
        $this->objectParam      = trim((string) Config::get('ADAXES_OBJECT_PARAM', 'directoryObject')) ?: 'directoryObject';
        $this->createPath       = trim((string) Config::get('ADAXES_CREATE_PATH', $this->objectsPath), '/');
        $this->modifyPath       = trim((string) Config::get('ADAXES_MODIFY_PATH', $this->objectsPath), '/');
        // Disable/enable default to the modify endpoint (toggling accountDisabled);
        // set an explicit operation path if your Adaxes build exposes one.
        $this->disablePath      = trim((string) Config::get('ADAXES_DISABLE_PATH', ''), '/');
        $this->enablePath       = trim((string) Config::get('ADAXES_ENABLE_PATH', ''), '/');
        // Group membership endpoints are version-specific — no default; the group
        // reconciler phase reports (dry-run) until these are configured.
        // The group-membership endpoint (same path for add/remove; POST adds,
        // DELETE removes). Adaxes 2025.x: {base}/api/directoryObjects/groupMembers.
        // ADAXES_GROUP_ADD_PATH is honored as a legacy override if present.
        $this->groupMembersPath = trim((string) (Config::get('ADAXES_GROUP_MEMBERS_PATH', '')
            ?: Config::get('ADAXES_GROUP_ADD_PATH', '')
            ?: 'api/directoryObjects/groupMembers'), '/');
        // Move endpoint (relocate an object to a different OU). Version-specific,
        // like the paths above; the 2025.x default posts both the object and the
        // destination container in the body. Two request shapes are supported so
        // this needn't change with the build: if ADAXES_MOVE_PATH contains the
        // literal "{id}", the object identity is URL-encoded into the path and only
        // the destination rides the body; otherwise both ride the body under the
        // configurable ADAXES_MOVE_OBJECT_FIELD / ADAXES_MOVE_DESTINATION_FIELD.
        $this->movePath        = trim((string) Config::get('ADAXES_MOVE_PATH', 'api/directoryObjects/move'), '/');
        // Adaxes' move endpoint requires DirectoryObject + TargetContainer in the
        // body (case-insensitive model binding, so camelCase matches the rest of
        // the API). Overridable in case a build names them differently.
        $this->moveObjectField = trim((string) Config::get('ADAXES_MOVE_OBJECT_FIELD', 'directoryObject')) ?: 'directoryObject';
        $this->moveDestField   = trim((string) Config::get('ADAXES_MOVE_DESTINATION_FIELD', 'targetContainer')) ?: 'targetContainer';
        $this->createObjectType = trim((string) Config::get('ADAXES_CREATE_OBJECT_TYPE', 'user')) ?: 'user';
        // Password-reset operation endpoint (2025.x default). Used right after a
        // create because Adaxes Business Rules don't fire on REST events, so IDM
        // sets the initial password + must-change-at-logon itself.
        $this->resetPasswordPath = trim((string) Config::get('ADAXES_RESET_PASSWORD_PATH', 'api/directoryObjects/resetPassword'), '/');

        $this->sessionPath = trim((string) Config::get('ADAXES_SESSION_PATH', 'api/authSessions/create'), '/');
        $this->tokenPath   = trim((string) Config::get('ADAXES_TOKEN_PATH', 'api/auth'), '/');

        $this->fetch = $fetch ?? fn(string $method, string $url, array $headers, ?string $body): ?array
            => $this->httpRequest($method, $url, $headers, $body);
    }

    /**
     * Writes are available only when explicitly enabled AND a base URL plus a
     * write credential (token or username+password) are present. False = the
     * read-only default; the reconciler stays in dry-run / no-op territory.
     */
    public function configured(): bool
    {
        return $this->writeEnabled
            && $this->baseUrl !== ''
            && ($this->token !== '' || ($this->username !== '' && $this->password !== ''));
    }

    /**
     * Create a User in the target OU with the given identity attributes and return
     * its new objectGUID. IDM links that GUID into the crosswalk immediately, so a
     * created-but-unlinked account can never happen silently.
     *
     * @param array<string,string> $attrs e.g. sAMAccountName, userPrincipalName, mail, displayName, givenName, sn, employeeID
     * @return CreateResult
     */
    public function create(string $containerDn, array $attrs): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'guid' => null, 'created' => false];
        }
        $containerDn = trim($containerDn);
        if ($containerDn === '') {
            return ['ok' => false, 'error' => 'No target OU (containerDn) for create.', 'guid' => null, 'created' => false];
        }

        // cn (the RDN) rides in the property list like every other attribute; AD
        // derives the object name from it. The CALLER owns making it unique within
        // the container (CN is the RDN, so a duplicate in the same OU fails).
        $body = [
            'createIn'   => $containerDn,
            'objectType' => $this->createObjectType,
            'properties' => self::propertyList($attrs),
        ];

        $url = $this->baseUrl . '/' . $this->createPath;
        $res = $this->request('POST', $url, (string) json_encode($body));
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'guid' => null, 'created' => false];
        }

        // Prefer the GUID the server returns; if the create response is bare (204),
        // the caller re-resolves via a search — but that is the caller's job.
        $guid = self::extractGuid($res['data']);
        return ['ok' => true, 'error' => null, 'guid' => $guid, 'created' => true];
    }

    /**
     * Set (reset) an account's password and, by default, force a change at next
     * logon. IDM calls this immediately after create: the deployed Adaxes Business
     * Rules do NOT fire on REST API events, so the generated-password +
     * must-change-at-logon that OneSync's create rule applied must be set here
     * explicitly. $objectGuid is the object identity (objectGUID or DN — the
     * resetPassword endpoint accepts either under `directoryObject`).
     *
     * The password rides in the request BODY (never the URL/query), and the
     * transport only ever debug-logs response snippets, so the secret is not
     * written to logs.
     *
     * @return ToggleResult
     */
    public function resetPassword(string $objectGuid, string $password, bool $mustChangePassword = true): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        $objectGuid = trim($objectGuid);
        if ($objectGuid === '') {
            return ['ok' => false, 'error' => 'No objectGUID for password reset.', 'changed' => false];
        }
        if ($password === '') {
            return ['ok' => false, 'error' => 'Refusing to set an empty password.', 'changed' => false];
        }

        $body = [
            $this->objectParam => $objectGuid,
            'password'         => $password,
            'options'          => ['mustChangePassword' => $mustChangePassword],
        ];
        $url = $this->baseUrl . '/' . $this->resetPasswordPath;
        $res = $this->request('POST', $url, (string) json_encode($body));
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
    }

    /**
     * Modify the given attributes on an existing account (identified by objectGUID).
     * Only the attributes passed are sent; never send sAMAccountName (immutable).
     * Returns the attributes that were pushed.
     *
     * @param array<string,string> $attrs
     * @return ModifyResult
     */
    public function modify(string $objectGuid, array $attrs): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => []];
        }
        $objectGuid = trim($objectGuid);
        if ($objectGuid === '') {
            return ['ok' => false, 'error' => 'No objectGUID for modify.', 'changed' => []];
        }
        // Guardrail: sAMAccountName is immutable — refuse to push it even if asked.
        $attrs = self::withoutImmutable($attrs);
        if ($attrs === []) {
            return ['ok' => true, 'error' => null, 'changed' => []]; // nothing to do
        }

        // Adaxes identifies the target in the BODY (directoryObject), not the URL.
        $body = ['directoryObject' => $objectGuid, 'properties' => self::propertyList($attrs)];
        $url  = $this->baseUrl . '/' . $this->modifyPath;

        $res = $this->request('PATCH', $url, (string) json_encode($body));
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'], 'changed' => []];
        }
        return ['ok' => true, 'error' => null, 'changed' => $attrs];
    }

    /**
     * Rename an account: set a new sAMAccountName (and typically userPrincipalName
     * + mail). This is the ONLY path that changes sAMAccountName — it bypasses the
     * modify() immutability guard because the rename workflow (a deliberate,
     * scheduled, notified last-name change) explicitly intends it. The object's
     * cn/DN is left as-is.
     *
     * @param array<string,string> $extra e.g. userPrincipalName, mail
     * @return ModifyResult
     */
    public function rename(string $objectGuid, string $newSamAccountName, array $extra = []): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => []];
        }
        $objectGuid = trim($objectGuid);
        $newSam = trim($newSamAccountName);
        if ($objectGuid === '' || $newSam === '') {
            return ['ok' => false, 'error' => 'objectGUID and new sAMAccountName are both required.', 'changed' => []];
        }
        $attrs = ['sAMAccountName' => $newSam] + $extra;
        $body = ['directoryObject' => $objectGuid, 'properties' => self::propertyList($attrs)];
        $url  = $this->baseUrl . '/' . $this->modifyPath;

        $res = $this->request('PATCH', $url, (string) json_encode($body));
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => $attrs]
            : ['ok' => false, 'error' => $res['error'], 'changed' => []];
    }

    /**
     * Set the account's full proxyAddresses list (the email aliases). Multi-valued,
     * so the caller reads the current list, adds/removes, and passes the new whole
     * list here (read-modify-write). The primary SMTP address is the `SMTP:` entry
     * (uppercase); secondaries/aliases are `smtp:` (lowercase).
     *
     * @param list<string> $addresses
     * @return ToggleResult
     */
    public function setProxyAddresses(string $objectGuid, array $addresses): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        $objectGuid = trim($objectGuid);
        if ($objectGuid === '') {
            return ['ok' => false, 'error' => 'No objectGUID.', 'changed' => false];
        }
        $values = array_values(array_filter(array_map('trim', $addresses), static fn($v) => $v !== ''));
        $body = ['directoryObject' => $objectGuid, 'properties' => self::propertyList(['proxyAddresses' => $values])];
        $url  = $this->baseUrl . '/' . $this->modifyPath;

        $res = $this->request('PATCH', $url, (string) json_encode($body));
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
    }

    /**
     * Relocate an account to a different OU (its container / parent). Identity
     * attributes and the CN are unchanged — only the object's parent moves. Used by
     * the edit phase to heal accounts sitting in the wrong OU (e.g. a bus aide that
     * was created under a building instead of the transportation OU).
     *
     * $objectGuid is the object identity (objectGUID or DN); $containerDn is the
     * destination OU's distinguishedName.
     *
     * @return ToggleResult
     */
    public function move(string $objectGuid, string $containerDn): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        $objectGuid  = trim($objectGuid);
        $containerDn = trim($containerDn);
        if ($objectGuid === '' || $containerDn === '') {
            return ['ok' => false, 'error' => 'objectGUID and destination container are both required for move.', 'changed' => false];
        }

        $inPath = str_contains($this->movePath, '{id}');
        $path   = $inPath ? str_replace('{id}', rawurlencode($objectGuid), $this->movePath) : $this->movePath;
        $body   = [$this->moveDestField => $containerDn];
        if (!$inPath) {
            $body[$this->moveObjectField] = $objectGuid;
        }

        $url = $this->baseUrl . '/' . $path;
        $res = $this->request('POST', $url, (string) json_encode($body));
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
    }

    /**
     * Disable an account. By default this toggles the `accountDisabled` property
     * through the modify endpoint; if ADAXES_DISABLE_PATH names a dedicated
     * operation endpoint, that is POSTed instead.
     *
     * @return ToggleResult
     */
    public function disable(string $objectGuid): array
    {
        return $this->setDisabled($objectGuid, true, $this->disablePath);
    }

    /** Re-enable a disabled account (the inverse of disable()). @return ToggleResult */
    public function enable(string $objectGuid): array
    {
        return $this->setDisabled($objectGuid, false, $this->enablePath);
    }

    /**
     * Clear accountExpires so the account never expires — the inverse of the
     * expire-leaver write. Sending an empty value list for the property removes it
     * (Adaxes "clear a property"). Used when reactivating a returning employee whose
     * old account carried a past expiration and who has no new end date to honor.
     *
     * @return ToggleResult
     */
    public function clearExpiration(string $objectGuid): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        $objectGuid = trim($objectGuid);
        if ($objectGuid === '') {
            return ['ok' => false, 'error' => 'No objectGUID.', 'changed' => false];
        }
        // An empty values array clears the property (never expires). propertyList()
        // can't emit an empty list from a scalar, so the property is built directly.
        $body = [
            'directoryObject' => $objectGuid,
            'properties'      => [['propertyName' => 'accountExpires', 'propertyType' => 'Timestamp', 'values' => []]],
        ];
        $res = $this->request('PATCH', $this->baseUrl . '/' . $this->modifyPath, (string) json_encode($body));
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
    }

    /**
     * Add a member to a group via the Adaxes REST API:
     *   POST {base}/api/directoryObjects/groupMembers  {"group": …, "newMember": …}
     * Both $group and $member are directory identifiers (distinguishedName or
     * objectGUID) — the caller resolves a group name to its DN/GUID first.
     *
     * @return ToggleResult
     */
    public function addToGroup(string $group, string $member): array
    {
        if (($guard = $this->groupGuard($group, $member)) !== null) {
            return $guard;
        }
        $url = $this->baseUrl . '/' . $this->groupMembersPath;
        $body = (string) json_encode(['group' => trim($group), 'newMember' => trim($member)]);
        $res = $this->request('POST', $url, $body);
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
    }

    /**
     * Remove a member from a group:
     *   DELETE {base}/api/directoryObjects/groupMembers?group=…&member=…   (no body)
     *
     * @return ToggleResult
     */
    public function removeFromGroup(string $group, string $member): array
    {
        if (($guard = $this->groupGuard($group, $member)) !== null) {
            return $guard;
        }
        $url = $this->baseUrl . '/' . $this->groupMembersPath
             . '?group=' . rawurlencode(trim($group))
             . '&member=' . rawurlencode(trim($member));
        $res = $this->request('DELETE', $url, null);
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
    }

    // ---- internals ----------------------------------------------------------

    /** Shared precondition for the group ops; null when everything is present. */
    private function groupGuard(string $group, string $member): ?array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        if (trim($group) === '' || trim($member) === '') {
            return ['ok' => false, 'error' => 'Group and member identifiers are both required.', 'changed' => false];
        }
        return null;
    }


    /**
     * Set/clear accountDisabled. Uses a dedicated operation endpoint when one is
     * configured ($operationPath), otherwise falls back to the modify endpoint.
     *
     * @return ToggleResult
     */
    private function setDisabled(string $objectGuid, bool $disabled, string $operationPath): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        $objectGuid = trim($objectGuid);
        if ($objectGuid === '') {
            return ['ok' => false, 'error' => 'No objectGUID for ' . ($disabled ? 'disable' : 'enable') . '.', 'changed' => false];
        }

        if ($operationPath !== '') {
            $url = $this->baseUrl . '/' . $operationPath
                 . '?' . $this->objectParam . '=' . rawurlencode($objectGuid);
            $res = $this->request('POST', $url, (string) json_encode([$this->objectParam => $objectGuid]));
            return $res['ok']
                ? ['ok' => true, 'error' => null, 'changed' => true]
                : ['ok' => false, 'error' => $res['error'], 'changed' => false];
        }

        // Default: flip the accountDisabled property via the modify endpoint.
        $res = $this->modify($objectGuid, ['accountDisabled' => $disabled ? 'true' : 'false']);
        return ['ok' => $res['ok'], 'error' => $res['error'], 'changed' => $res['ok']];
    }

    /** Human-readable "why nothing happened" for the disabled/off state. */
    private function disabledReason(): string
    {
        if (!$this->writeEnabled) {
            return 'Adaxes writes are disabled (set ADAXES_WRITE_ENABLED=true to enable).';
        }
        return 'Adaxes writer is not configured (set ADAXES_BASE_URL and a write token, or username + password).';
    }

    /** Attributes that never change once assigned — dropped from any modify. */
    private const IMMUTABLE = ['samaccountname'];

    /**
     * @param array<string,string> $attrs
     * @return array<string,string>
     */
    private static function withoutImmutable(array $attrs): array
    {
        $out = [];
        foreach ($attrs as $name => $value) {
            if (!in_array(strtolower((string) $name), self::IMMUTABLE, true)) {
                $out[$name] = $value;
            }
        }
        return $out;
    }

    /**
     * Render an attribute map as the Adaxes REST property list. Each entry is
     * {propertyName, propertyType, values:[…]} — the shape the create/modify
     * endpoints require (NOT {name, value}). Type is inferred from the attribute
     * (see propertyType); values is always an array so multi-valued attributes
     * (proxyAddresses) work unchanged. A null value is dropped from the list.
     *
     * @param array<string,string|int|list<string>> $attrs
     * @return list<array{propertyName:string,propertyType:string,values:list<string|int>}>
     */
    private static function propertyList(array $attrs): array
    {
        $list = [];
        foreach ($attrs as $name => $value) {
            if ($value === null) {
                continue;
            }
            $type = self::propertyType((string) $name);
            $raw = is_array($value) ? array_values($value) : [$value];
            $values = [];
            foreach ($raw as $v) {
                if ($v === null) {
                    continue;
                }
                $values[] = $type === 'Integer' ? (int) $v : (string) $v;
            }
            $list[] = ['propertyName' => (string) $name, 'propertyType' => $type, 'values' => $values];
        }
        return $list;
    }

    /**
     * The Adaxes REST propertyType for an LDAP attribute. Almost everything IDM
     * writes is a String; accountExpires is a Timestamp (ISO-8601 value) and
     * userAccountControl an Integer. See the "Setting property values" REST docs.
     */
    private static function propertyType(string $name): string
    {
        return match (strtolower(trim($name))) {
            'accountexpires', 'accountexpirationdate' => 'Timestamp',
            'useraccountcontrol'                       => 'Integer',
            default                                    => 'String',
        };
    }

    /**
     * Pull a normalized objectGUID out of a create/get response, if present and
     * well-formed. Tolerates the list/map property shapes and a top-level field;
     * returns null for a missing or non-GUID value so we never link junk.
     *
     * @param array<string,mixed> $data
     */
    private static function extractGuid(array $data): ?string
    {
        $candidates = [];

        // Top-level objectGUID / guid.
        foreach (['objectGUID', 'objectGuid', 'guid'] as $k) {
            if (isset($data[$k]) && is_scalar($data[$k])) {
                $candidates[] = (string) $data[$k];
            }
        }

        // properties: list form [{name,value}] or map form {name:value}.
        $props = $data['properties'] ?? null;
        if (is_array($props)) {
            if (array_is_list($props)) {
                foreach ($props as $p) {
                    if (is_array($p) && strtolower((string) ($p['name'] ?? '')) === 'objectguid' && is_scalar($p['value'] ?? null)) {
                        $candidates[] = (string) $p['value'];
                    }
                }
            } else {
                foreach ($props as $name => $value) {
                    if (strtolower((string) $name) === 'objectguid' && is_scalar($value)) {
                        $candidates[] = (string) $value;
                    }
                }
            }
        }

        foreach ($candidates as $raw) {
            $raw = trim(trim($raw), '{}');
            if (preg_match('/^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$/', $raw)) {
                return $raw;
            }
        }
        return null;
    }
}
