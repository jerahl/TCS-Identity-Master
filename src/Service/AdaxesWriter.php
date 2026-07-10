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
 * licensing, initial password policy) is left to Adaxes Business Rules that fire
 * on the create — IDM sends only the identity core.
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
    private string $createObjectType;

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
        $this->createObjectType = trim((string) Config::get('ADAXES_CREATE_OBJECT_TYPE', 'user')) ?: 'user';

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

        // The object's name (its CN / RDN) rides top-level, not as a property —
        // lift a 'cn' attribute out of the map. AD derives cn from the name, and
        // the CALLER owns making it unique within the container (CN is the RDN,
        // so a duplicate in the same OU fails the create).
        $name = trim((string) ($attrs['cn'] ?? $attrs['name'] ?? ''));
        unset($attrs['cn'], $attrs['name']);

        $body = [
            'objectType' => $this->createObjectType,
            'path'       => $containerDn,
            'properties' => self::propertyList($attrs),
        ];
        if ($name !== '') {
            $body['name'] = $name;
        }

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

        $body = ['properties' => self::propertyList($attrs)];
        $url  = $this->baseUrl . '/' . $this->modifyPath
              . '?' . $this->objectParam . '=' . rawurlencode($objectGuid);

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
        $body = ['properties' => self::propertyList($attrs)];
        $url  = $this->baseUrl . '/' . $this->modifyPath . '?' . $this->objectParam . '=' . rawurlencode($objectGuid);

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
        $body = ['properties' => [['name' => 'proxyAddresses', 'value' => $values]]];
        $url  = $this->baseUrl . '/' . $this->modifyPath . '?' . $this->objectParam . '=' . rawurlencode($objectGuid);

        $res = $this->request('PATCH', $url, (string) json_encode($body));
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
     * Render an attribute map as the Adaxes {name, value} property list.
     *
     * @param array<string,string> $attrs
     * @return list<array{name:string,value:string}>
     */
    private static function propertyList(array $attrs): array
    {
        $list = [];
        foreach ($attrs as $name => $value) {
            $list[] = ['name' => (string) $name, 'value' => (string) $value];
        }
        return $list;
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
