<?php

declare(strict_types=1);

namespace App\View;

/**
 * Presentation lookups shared by templates — labels and CSS modifier classes for
 * enums. Mirrors the mockup's status/type maps; colors live in app.css so this
 * stays markup-agnostic.
 */
final class Present
{
    private const STATUS = [
        'active'     => 'Active',
        'pending'    => 'Pending',
        'disabled'   => 'Disabled',
        'terminated' => 'Terminated',
    ];

    private const TYPE = [
        'faculty'    => 'Faculty',
        'staff'      => 'Staff',
        'contractor' => 'Contractor',
        'sub'        => 'Substitute',
        'intern'     => 'Intern',
        'other'      => 'Other',
    ];

    /** @return array{label:string, mod:string} */
    public static function status(string $status): array
    {
        return [
            'label' => self::STATUS[$status] ?? ucfirst($status),
            'mod'   => array_key_exists($status, self::STATUS) ? $status : 'pending',
        ];
    }

    public static function type(string $type): string
    {
        return self::TYPE[$type] ?? ucfirst($type);
    }

    /** Modifier class for a per-account sync status badge. */
    public static function syncMod(?string $lastStatus): string
    {
        return match ($lastStatus) {
            'Success' => 'ok',
            'Fail'    => 'fail',
            'Skipped' => 'muted',
            default   => 'new',
        };
    }

    /** Friendly label for a person_source_id.system value. */
    public static function sourceSystem(string $system): string
    {
        return match ($system) {
            'nextgen'     => 'NextGen',
            'powerschool' => 'PowerSchool',
            'ad'          => 'Active Directory',
            'google'      => 'Google',
            'intern_csv'  => 'Intern',
            'alsde'       => 'ALSDE',
            'onesync'     => 'OneSync',
            'manual'      => 'Manual',
            default       => ucfirst($system),
        };
    }

    public static function initials(string $first, string $last): string
    {
        return strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
    }

    /** Modifier class for an import_batch status badge. */
    public static function importMod(?string $status): string
    {
        return match ($status) {
            'complete' => 'ok',
            'running'  => 'run',
            'failed'   => 'fail',
            default    => 'muted',
        };
    }

    /** Label + modifier for a staging_record.match_status outcome. */
    public static function matchOutcome(string $status): array
    {
        return match ($status) {
            'auto_matched' => ['Matched existing', 'ok'],
            'merged'       => ['Linked (review)', 'ok'],
            'new'          => ['New record', 'info'],
            'needs_review' => ['Needs review', 'warn'],
            'skipped'      => ['Skipped', 'muted'],
            default        => [ucfirst($status), 'muted'],
        };
    }

    /**
     * Label + modifier for a dry-run outcome (Importer action key). Phrased in the
     * conditional — nothing is written on a dry run, this is what WOULD happen.
     */
    public static function dryRunOutcome(string $action): array
    {
        return match ($action) {
            'auto_match'   => ['Would update existing', 'ok'],
            'new'          => ['Would create new', 'info'],
            'needs_review' => ['Would queue for review', 'warn'],
            'skipped'      => ['No change', 'muted'],
            'error'        => ['Error — row skipped', 'fail'],
            default        => [ucfirst($action), 'muted'],
        };
    }
}
