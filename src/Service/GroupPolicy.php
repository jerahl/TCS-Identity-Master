<?php

declare(strict_types=1);

namespace App\Service;

use App\Config;

/**
 * Computes the AD group memberships a person should have — the Phase 4 identity
 * policy that replaces OneSync's Faculty AD group mappings (see
 * docs/adaxes-provisioning-design.md). Pure logic, no I/O: it takes the
 * structured facts IDM already owns (title, person_type, building, whether the
 * person is transportation) and returns a set of group names (cn), so it
 * unit-tests without a DB or a live directory. The reconciler wires the live
 * memberOf diff around it.
 *
 * Rules (from the OneSync destination, verbatim):
 *  - ALL-Faculty — everyone.
 *  - Per-school Everyone group — from the building OU token: OU=CO → "CO-Everyone"
 *    (RQES→RQS and UPE→UP are naming exceptions).
 *  - Transportation — everyone in transportation (Bus Drivers).
 *  - Microsoft 365 licensing (exactly one): the A1 group if the title contains
 *    CNP / custodian / bus driver / aide / sub / intern / SRO, or the person is a
 *    contractor/sub/intern; otherwise the A3 group.
 *  - Raptor role (exactly one, first match wins): BuildingAdmin (Principal, IT
 *    Computer Tech), ClientAdmin (IT Technician Supervisor, Safety Contractor,
 *    Director of Technology), EntryAdmin (Secretary, bookkeeper), GlobalAdmin
 *    (Network Administrator, Security Specialist), else EmergencyManagementUser.
 *  - Raptor StudentSafeUser — granted IN ADDITION to the role above (not
 *    mutually exclusive) when the title is Principal, Assistant Principal,
 *    Social Worker, or Counselor. Suppressed only by a 'none' Raptor override.
 *
 * Group *names* are configurable (the exact AD names must be confirmed before
 * enabling); the matching *conditions* are the load-bearing policy encoded here.
 */
final class GroupPolicy
{
    /**
     * Everyone-group building-token naming exceptions (OU token → group token):
     * the per-school Everyone group is "<token><suffix>", but some buildings name
     * their group differently from their OU. Both spellings of the University
     * Place / Rock Quarry tokens are mapped so it's correct whether school.ad_ou
     * uses UP/RQS or UPE/RQES. STC (the Sprayberry campus tenants) share the
     * Central Office group.
     */
    private const SCHOOL_TOKEN_REMAP = [
        'UP'  => 'UPES',
        'UPE' => 'UPES',
        'OKD' => 'OAKD',
        'RQS' => 'RQES',
        'OKH' => 'OAKH',
        'CES' => 'CPS',
        'STC' => 'CO',
    ];

    /** Substring title keywords that put a person on the M365 A1 (vs A3) license. */
    private const A1_TITLE_KEYWORDS = ['CNP', 'custodian', 'bus driver', 'aide', 'sub', 'intern'];

    /** person_type values that always get the A1 license. */
    private const A1_TYPES = ['contractor', 'sub', 'intern'];

    /**
     * Raptor role groups, in priority order (first title match wins). Each carries
     * a STABLE role key (used to store a per-person override — robust to the AD
     * group name changing), the env key its name is configurable under, its default
     * name, and the title keywords that assign it.
     */
    private const RAPTOR_RULES = [
        'buildingadmin' => ['env' => 'AD_GROUP_RAPTOR_BUILDING_ADMIN', 'default' => 'Raptor_BuildingAdmin', 'keywords' => ['Principal', 'IT Computer Tech']],
        'clientadmin'   => ['env' => 'AD_GROUP_RAPTOR_CLIENT_ADMIN',   'default' => 'Raptor_ClientAdmin',   'keywords' => ['IT Technician Supervisor', 'Safety Contractor', 'Director of Technology']],
        'entryadmin'    => ['env' => 'AD_GROUP_RAPTOR_ENTRY_ADMIN',    'default' => 'Raptor_EntryAdmin',    'keywords' => ['Secretary', 'bookkeeper']],
        'globaladmin'   => ['env' => 'AD_GROUP_RAPTOR_GLOBAL_ADMIN',   'default' => 'Raptor_GlobalAdmin',   'keywords' => ['Network Administrator', 'Security Specialist']],
    ];
    /** Role key + env/default for the fallback Raptor group (everyone with no title match). */
    private const RAPTOR_DEFAULT_KEY = 'emergency';
    private const RAPTOR_DEFAULT_ENV = 'AD_GROUP_RAPTOR_DEFAULT';
    private const RAPTOR_DEFAULT_NAME = 'Raptor_EmergencyManagementUser';

    /**
     * Additive Raptor group (env key, default name, title keywords). Unlike the
     * mutually-exclusive role above, StudentSafeUser is granted ON TOP OF a
     * person's Raptor role whenever the title matches — a Principal keeps
     * BuildingAdmin AND joins StudentSafeUser. ('Principal' also matches
     * 'Assistant Principal' as a substring; both are listed for clarity.)
     */
    private const RAPTOR_STUDENT_SAFE_ENV = 'AD_GROUP_RAPTOR_STUDENT_SAFE';
    private const RAPTOR_STUDENT_SAFE_NAME = 'Raptor_StudentSafeUser';
    private const RAPTOR_STUDENT_SAFE_KEYWORDS = ['Principal', 'Assistant Principal', 'Social Worker', 'Counselor'];

    private string $allFaculty;
    private string $transportation;
    private string $everyoneSuffix;
    private string $a1;
    private string $a3;
    /** @var array<string,string> Raptor role key → configured group cn (priority order). */
    private array $raptorGroups;
    private string $raptorDefault;
    /** Additive Raptor group cn (StudentSafeUser), granted alongside the role. */
    private string $raptorStudentSafe;

    public function __construct(
        ?string $allFaculty = null,
        ?string $transportation = null,
        ?string $everyoneSuffix = null,
        ?string $a1 = null,
        ?string $a3 = null,
    ) {
        $this->allFaculty     = $allFaculty     ?? (string) Config::get('AD_GROUP_ALL_FACULTY', 'All-Faculty');
        $this->transportation = $transportation ?? (string) Config::get('AD_GROUP_TRANSPORTATION', 'Transportation');
        $this->everyoneSuffix = $everyoneSuffix ?? (string) Config::get('AD_GROUP_EVERYONE_SUFFIX', '-Everyone');
        $this->a1             = $a1             ?? (string) Config::get('AD_GROUP_M365_A1', 'M365 A1 License');
        $this->a3             = $a3             ?? (string) Config::get('AD_GROUP_M365_A3', 'M365 A3 License');

        $this->raptorGroups = [];
        foreach (self::RAPTOR_RULES as $key => $rule) {
            $this->raptorGroups[$key] = (string) Config::get($rule['env'], $rule['default']);
        }
        $this->raptorDefault = (string) Config::get(self::RAPTOR_DEFAULT_ENV, self::RAPTOR_DEFAULT_NAME);
        $this->raptorStudentSafe = (string) Config::get(self::RAPTOR_STUDENT_SAFE_ENV, self::RAPTOR_STUDENT_SAFE_NAME);
    }

    /**
     * The group names (cn) a person should belong to.
     *
     * @param string $schoolToken     the building OU token (e.g. "CO"); '' if unknown
     * @param bool   $isTransportation true for Bus Drivers / transportation staff
     * @param string $raptorOverride  per-person Raptor exception (a role key from
     *        raptorRoleOptions()): '' = automatic by title, 'none' = no Raptor
     *        group, else force that role. Unknown values fail safe to the title rule.
     * @return list<string> deduped, stable order
     */
    public function desiredGroups(string $title, string $personType, string $schoolToken, bool $isTransportation, string $raptorOverride = ''): array
    {
        $groups = [$this->allFaculty];

        if ($schoolToken !== '') {
            $groups[] = $this->everyoneGroup($schoolToken);
        }
        if ($isTransportation) {
            $groups[] = $this->transportation;
        }
        $groups[] = self::isA1($title, $personType) ? $this->a1 : $this->a3;
        $groups[] = $this->resolveRaptor($title, $raptorOverride);
        // StudentSafeUser is additive (on top of the role), by title — but a
        // 'none' override opts the person out of every Raptor group, this one too.
        if (strtolower(trim($raptorOverride)) !== 'none') {
            $groups[] = $this->studentSafeGroup($title);
        }

        // Dedupe, preserve order, drop any empties.
        $out = [];
        foreach ($groups as $g) {
            $g = trim($g);
            if ($g !== '' && !in_array($g, $out, true)) {
                $out[] = $g;
            }
        }
        return $out;
    }

    /**
     * The Raptor group cn for a person, honoring a per-person override. '' means no
     * Raptor group (an explicit 'none' exception). See desiredGroups() for the
     * override semantics.
     */
    public function resolveRaptor(string $title, string $override = ''): string
    {
        $ov = strtolower(trim($override));
        if ($ov === '') {
            return $this->raptorGroup($title);       // automatic (by title)
        }
        if ($ov === 'none') {
            return '';                               // explicit exclusion
        }
        if ($ov === self::RAPTOR_DEFAULT_KEY) {
            return $this->raptorDefault;
        }
        if (isset($this->raptorGroups[$ov])) {
            return $this->raptorGroups[$ov];
        }
        return $this->raptorGroup($title);           // unknown key → fail safe
    }

    /**
     * The additive Raptor StudentSafeUser group cn for a person, or '' when the
     * title doesn't qualify. Granted alongside (not instead of) the Raptor role;
     * see desiredGroups() for how a 'none' override suppresses it.
     */
    public function studentSafeGroup(string $title): string
    {
        return self::titleContainsAny($title, self::RAPTOR_STUDENT_SAFE_KEYWORDS)
            ? $this->raptorStudentSafe
            : '';
    }

    /**
     * The per-person Raptor override choices for the admin control: stable role key
     * → the label shown. '' = automatic; each role key maps to its (configured) AD
     * group name; 'none' excludes the person from every Raptor group.
     *
     * @return array<string,string>
     */
    public function raptorRoleOptions(): array
    {
        $opts = ['' => 'Automatic (by job title)'];
        foreach ($this->raptorGroups as $key => $cn) {
            $opts[$key] = $cn;
        }
        $opts[self::RAPTOR_DEFAULT_KEY] = $this->raptorDefault;
        $opts['none'] = 'None (exclude from all Raptor groups)';
        return $opts;
    }

    /** True when $key is an accepted Raptor override value (a key from raptorRoleOptions()). */
    public function isValidRaptorOverride(string $key): bool
    {
        return array_key_exists(strtolower(trim($key)), $this->raptorRoleOptions());
    }

    /** The per-school Everyone group name for a building OU token (with remaps). */
    public function everyoneGroup(string $schoolToken): string
    {
        $token = strtoupper(trim($schoolToken));
        $token = self::SCHOOL_TOKEN_REMAP[$token] ?? $token;
        return $token . $this->everyoneSuffix;
    }

    /**
     * The groups the policy can ever assign that are NOT per-school — All-Faculty,
     * Transportation, both M365 licenses, every Raptor role, and the additive
     * Raptor StudentSafeUser group. Combined with the
     * per-school Everyone groups (via managedGroups()) this is the *exact* set the
     * reconciler is allowed to remove someone from.
     *
     * @return list<string>
     */
    public function fixedManagedGroups(): array
    {
        $fixed = [$this->allFaculty, $this->transportation, $this->a1, $this->a3, $this->raptorDefault, $this->raptorStudentSafe];
        foreach ($this->raptorGroups as $cn) {
            $fixed[] = $cn;
        }
        return array_values(array_filter($fixed, static fn($g) => trim($g) !== ''));
    }

    /**
     * The complete set of IDM-managed group names, lowercased for O(1) lookup:
     * the fixed groups plus the Everyone group for every known building token.
     * A group NOT in this set is a custom/manual group — the reconciler adds and
     * removes only within this set, so manual memberships are never disturbed.
     *
     * @param list<string> $schoolTokens every building's OU token (from the DB)
     * @return array<string,true>
     */
    public function managedGroups(array $schoolTokens): array
    {
        $set = [];
        foreach ($this->fixedManagedGroups() as $g) {
            $set[strtolower($g)] = true;
        }
        foreach ($schoolTokens as $token) {
            $token = trim($token);
            if ($token !== '') {
                $set[strtolower($this->everyoneGroup($token))] = true;
            }
        }
        return $set;
    }

    // ---- matching ------------------------------------------------------------

    private static function isA1(string $title, string $personType): bool
    {
        if (in_array(strtolower(trim($personType)), self::A1_TYPES, true)) {
            return true;
        }
        if (self::isSro($title)) {
            return true;
        }
        return self::titleContainsAny($title, self::A1_TITLE_KEYWORDS);
    }

    private function raptorGroup(string $title): string
    {
        foreach (self::RAPTOR_RULES as $key => $rule) {
            if (self::titleContainsAny($title, $rule['keywords'])) {
                return $this->raptorGroups[$key];
            }
        }
        return $this->raptorDefault;
    }

    /** SRO detection — the substring "sro" isn't in "School Resource Officer", so match the token/phrase. */
    private static function isSro(string $title): bool
    {
        return (bool) preg_match('/\bSRO\b|school\s+resource\s+officer/i', $title);
    }

    /** @param list<string> $keywords case-insensitive substring match */
    private static function titleContainsAny(string $title, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if ($kw !== '' && stripos($title, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}
