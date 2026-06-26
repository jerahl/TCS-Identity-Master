<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * The canonical downstream destinations OneSync provisions to. Each person gets
 * one account_sync_status row per destination; this registry gives a stable
 * name/order and lets the UI always show a status for every destination
 * (filling "not synced yet" for any that haven't reported).
 *
 * `match` keywords map whatever label OneSync actually writes to a canonical
 * destination (e.g. "Faculty AD" / "Staff AD" -> Active Directory).
 */
final class Destinations
{
    /** @var array<int,array{key:string,label:string,type:string,match:string[]}> */
    private const CANON = [
        ['key' => 'ad',          'label' => 'Active Directory', 'type' => 'ActiveDirectory', 'match' => ['active directory', 'azure', 'entra', ' ad', 'ad ', 'faculty ad', 'staff ad']],
        ['key' => 'google',      'label' => 'Google Workspace', 'type' => 'GSuite',          'match' => ['google', 'gsuite', 'workspace']],
        ['key' => 'raptor',      'label' => 'Raptor',           'type' => 'CSV',             'match' => ['raptor']],
        ['key' => 'powerschool', 'label' => 'PowerSchool',      'type' => 'CSV',             'match' => ['powerschool', 'power school']],
    ];

    /** @return array<int,array{key:string,label:string,type:string}> */
    public static function all(): array
    {
        return array_map(static fn($d) => ['key' => $d['key'], 'label' => $d['label'], 'type' => $d['type']], self::CANON);
    }

    /**
     * Overlay reported account_sync_status rows onto the canonical destinations.
     * Returns the four canonical destinations in order (each with its reported
     * status or a not-synced placeholder), then any extra/unknown destinations.
     *
     * @param array<int,array<string,mixed>> $reported rows from account_sync_status
     * @return array<int,array<string,mixed>>
     */
    public static function merge(array $reported): array
    {
        $used = [];
        $out = [];

        foreach (self::CANON as $canon) {
            $hit = null;
            foreach ($reported as $i => $row) {
                if (isset($used[$i])) {
                    continue;
                }
                if (self::matches($canon, (string) ($row['destination'] ?? ''))) {
                    $hit = $row;
                    $used[$i] = true;
                    break;
                }
            }
            $out[] = $hit !== null
                ? $hit + ['label' => $canon['label'], 'reported' => true]
                : [
                    'destination' => $canon['label'], 'label' => $canon['label'], 'dest_type' => $canon['type'],
                    'last_action' => null, 'last_status' => null, 'last_sync_at' => null, 'message' => null,
                    'reported' => false,
                ];
        }

        // Any reported destinations that didn't map to a canonical one — still show.
        foreach ($reported as $i => $row) {
            if (!isset($used[$i])) {
                $out[] = $row + ['label' => (string) ($row['destination'] ?? 'Other'), 'reported' => true];
            }
        }

        return $out;
    }

    private static function matches(array $canon, string $destination): bool
    {
        $d = mb_strtolower(trim($destination));
        if ($d === '') {
            return false;
        }
        if ($d === mb_strtolower($canon['label'])) {
            return true;
        }
        if ($d === $canon['key']) {
            return true;
        }
        foreach ($canon['match'] as $kw) {
            if (str_contains($d, trim($kw))) {
                return true;
            }
        }
        return false;
    }
}
