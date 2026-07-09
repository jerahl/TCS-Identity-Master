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
 *
 * Group *names* are configurable (the exact AD names must be confirmed before
 * enabling); the matching *conditions* are the load-bearing policy encoded here.
 */
final class GroupPolicy
{
    /** Everyone-group building-token naming exceptions (OU token → group token). */
    private const SCHOOL_TOKEN_REMAP = ['RQES' => 'RQS', 'UPE' => 'UP'];

    /** Substring title keywords that put a person on the M365 A1 (vs A3) license. */
    private const A1_TITLE_KEYWORDS = ['CNP', 'custodian', 'bus driver', 'aide', 'sub', 'intern'];

    /** person_type values that always get the A1 license. */
    private const A1_TYPES = ['contractor', 'sub', 'intern'];

    /** Raptor role groups, in priority order (first title match wins). */
    private const RAPTOR_RULES = [
        ['group' => 'Raptor_BuildingAdmin', 'keywords' => ['Principal', 'IT Computer Tech']],
        ['group' => 'Raptor_ClientAdmin',   'keywords' => ['IT Technician Supervisor', 'Safety Contractor', 'Director of Technology']],
        ['group' => 'Raptor_EntryAdmin',    'keywords' => ['Secretary', 'bookkeeper']],
        ['group' => 'Raptor_GlobalAdmin',   'keywords' => ['Network Administrator', 'Security Specialist']],
    ];
    private const RAPTOR_DEFAULT = 'Raptor_EmergencyManagementUser';

    private string $allFaculty;
    private string $transportation;
    private string $everyoneSuffix;
    private string $a1;
    private string $a3;

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
    }

    /**
     * The group names (cn) a person should belong to.
     *
     * @param string $schoolToken   the building OU token (e.g. "CO"); '' if unknown
     * @param bool   $isTransportation true for Bus Drivers / transportation staff
     * @return list<string> deduped, stable order
     */
    public function desiredGroups(string $title, string $personType, string $schoolToken, bool $isTransportation): array
    {
        $groups = [$this->allFaculty];

        if ($schoolToken !== '') {
            $groups[] = $this->everyoneGroup($schoolToken);
        }
        if ($isTransportation) {
            $groups[] = $this->transportation;
        }
        $groups[] = self::isA1($title, $personType) ? $this->a1 : $this->a3;
        $groups[] = self::raptorGroup($title);

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

    /** The per-school Everyone group name for a building OU token (with remaps). */
    public function everyoneGroup(string $schoolToken): string
    {
        $token = strtoupper(trim($schoolToken));
        $token = self::SCHOOL_TOKEN_REMAP[$token] ?? $token;
        return $token . $this->everyoneSuffix;
    }

    /**
     * Whether IDM manages this group's membership — i.e. it is one the policy can
     * assign, so the reconciler may REMOVE a person from it when they no longer
     * qualify. Any group outside this set is left untouched (never removed).
     */
    public function isManaged(string $groupCn): bool
    {
        $cn = trim($groupCn);
        if ($cn === '') {
            return false;
        }
        $fixed = [$this->allFaculty, $this->transportation, $this->a1, $this->a3, self::RAPTOR_DEFAULT];
        foreach (self::RAPTOR_RULES as $r) {
            $fixed[] = $r['group'];
        }
        foreach ($fixed as $f) {
            if (strcasecmp($cn, $f) === 0) {
                return true;
            }
        }
        // Any per-school Everyone group (…-Everyone) is ours.
        return $this->everyoneSuffix !== '' && stripos($cn, $this->everyoneSuffix) === strlen($cn) - strlen($this->everyoneSuffix);
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

    private static function raptorGroup(string $title): string
    {
        foreach (self::RAPTOR_RULES as $rule) {
            if (self::titleContainsAny($title, $rule['keywords'])) {
                return $rule['group'];
            }
        }
        return self::RAPTOR_DEFAULT;
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
