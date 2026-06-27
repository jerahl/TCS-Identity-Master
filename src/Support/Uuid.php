<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 4122 version-4 UUID generation. Used for person.person_uuid — the stable
 * external key shared with OneSync (uniqueId).
 */
final class Uuid
{
    public static function v4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // version 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variant 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
