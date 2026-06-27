<?php

declare(strict_types=1);

namespace App\Sync;

/**
 * Classifies how fresh a timestamp is, for staleness indicators:
 *   - 'never' : nothing recorded (OneSync hasn't run / no import yet)
 *   - 'stale' : older than the threshold (a run was missed / data is old)
 *   - 'fresh' : within the threshold
 *
 * Pure (takes "now" as a unix timestamp) so it's unit-testable.
 */
final class Freshness
{
    public const FRESH = 'fresh';
    public const STALE = 'stale';
    public const NEVER = 'never';

    /**
     * @return array{state:string, ageHours:?float, label:string, at:?string}
     */
    public static function classify(?string $lastAt, int $staleHours, int $nowTs): array
    {
        if ($lastAt === null || trim($lastAt) === '') {
            return ['state' => self::NEVER, 'ageHours' => null, 'label' => 'never', 'at' => null];
        }
        $ts = strtotime($lastAt);
        if ($ts === false) {
            return ['state' => self::NEVER, 'ageHours' => null, 'label' => 'unknown', 'at' => $lastAt];
        }
        $ageSecs = max(0, $nowTs - $ts);
        $ageHours = $ageSecs / 3600;
        return [
            'state' => $ageHours > $staleHours ? self::STALE : self::FRESH,
            'ageHours' => $ageHours,
            'label' => self::ago($ageSecs),
            'at' => $lastAt,
        ];
    }

    /** Human "time ago" label. */
    public static function ago(int $secs): string
    {
        if ($secs < 60) {
            return 'just now';
        }
        $m = intdiv($secs, 60);
        if ($m < 60) {
            return $m . 'm ago';
        }
        $h = intdiv($m, 60);
        if ($h < 48) {
            return $h . 'h ago';
        }
        return intdiv($h, 24) . 'd ago';
    }
}
