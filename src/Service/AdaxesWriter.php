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
    private string $groupAddPath;
    private string $groupRemovePath;
    private string $groupParam;
    private string $memberParam;
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
        $this->groupAddPath     = trim((string) Config::get('ADAXES_GROUP_ADD_PATH', ''), '/');
        $this->groupRemovePath  = trim((string) Config::get('ADAXES_GROUP_REMOVE_PATH', ''), '/');
        $this->groupParam       = trim((string) Config::get('ADAXES_GROUP_PARAM', 'group')) ?: 'group';
        $this->memberParam      = trim((string) Config::get('ADAXES_MEMBER_PARAM', 'member')) ?: 'member';
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
     * Add a member (by objectGUID) to a group (by DN, GUID, or cn — whatever the
     * configured endpoint accepts). Requires ADAXES_GROUP_ADD_PATH; without it the
     * group reconciler stays in report-only mode.
     *
     * @return ToggleResult
     */
    public function addToGroup(string $group, string $memberGuid): array
    {
        return $this->groupOp($this->groupAddPath, 'ADAXES_GROUP_ADD_PATH', $group, $memberGuid);
    }

    /** Remove a member from a group (the inverse of addToGroup). @return ToggleResult */
    public function removeFromGroup(string $group, string $memberGuid): array
    {
        return $this->groupOp($this->groupRemovePath, 'ADAXES_GROUP_REMOVE_PATH', $group, $memberGuid);
    }

    // ---- internals ----------------------------------------------------------

    /**
     * POST a membership change to a configurable endpoint, passing the group and
     * member both as query params and in the JSON body (endpoints differ on which
     * they read). No-op with a clear error when the path isn't configured.
     *
     * @return ToggleResult
     */
    private function groupOp(string $path, string $envName, string $group, string $memberGuid): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => $this->disabledReason(), 'changed' => false];
        }
        $group = trim($group);
        $memberGuid = trim($memberGuid);
        if ($group === '' || $memberGuid === '') {
            return ['ok' => false, 'error' => 'Group and member are both required.', 'changed' => false];
        }
        if ($path === '') {
            return ['ok' => false, 'error' => "Group membership endpoint is not configured (set {$envName}).", 'changed' => false];
        }

        $url = $this->baseUrl . '/' . $path
             . '?' . $this->groupParam . '=' . rawurlencode($group)
             . '&' . $this->memberParam . '=' . rawurlencode($memberGuid);
        $body = (string) json_encode([$this->groupParam => $group, $this->memberParam => $memberGuid]);
        $res = $this->request('POST', $url, $body);
        return $res['ok']
            ? ['ok' => true, 'error' => null, 'changed' => true]
            : ['ok' => false, 'error' => $res['error'], 'changed' => false];
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
