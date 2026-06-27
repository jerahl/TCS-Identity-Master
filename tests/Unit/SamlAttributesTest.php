<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\SamlProvider;
use PHPUnit\Framework\TestCase;

/**
 * Mapping a ClassLink SAML assertion to an email + display name. ClassLink
 * attribute names are SP-defined and admin-typed, so extraction must accept our
 * configured names, common variants, and be case-insensitive — while NameID
 * (set to emailAddress in ClassLink) remains the preferred key.
 */
final class SamlAttributesTest extends TestCase
{
    public function testEmailFromEmailFormatNameId(): void
    {
        self::assertSame(
            'jdoe@tuscaloosacityschools.com',
            SamlProvider::extractEmail('jdoe@tuscaloosacityschools.com', [])
        );
    }

    public function testEmailFromConfiguredAttributeWhenNameIdNotEmail(): void
    {
        $attrs = ['email' => ['jdoe@tuscaloosacityschools.com']];
        self::assertSame('jdoe@tuscaloosacityschools.com', SamlProvider::extractEmail('opaque-123', $attrs));
    }

    public function testEmailAttributeIsCaseInsensitive(): void
    {
        $attrs = ['Email' => ['JDoe@tuscaloosacityschools.com']];
        self::assertSame('JDoe@tuscaloosacityschools.com', SamlProvider::extractEmail('opaque-123', $attrs));
    }

    public function testEmailFallsBackToNameIdWhenNoAttribute(): void
    {
        self::assertSame('opaque-123', SamlProvider::extractEmail('opaque-123', []));
    }

    public function testDisplayNamePreferredOverParts(): void
    {
        $attrs = ['displayName' => ['Jane Doe'], 'firstName' => ['Jane'], 'lastName' => ['Doe']];
        self::assertSame('Jane Doe', SamlProvider::extractDisplayName($attrs));
    }

    public function testDisplayNameBuiltFromFirstAndLast(): void
    {
        $attrs = ['firstName' => ['Jane'], 'lastName' => ['Doe']];
        self::assertSame('Jane Doe', SamlProvider::extractDisplayName($attrs));
    }

    public function testDisplayNameFromClassLinkGivenFamilyVariants(): void
    {
        // ClassLink "Given Name" / "Family Name" mapped to common variant names.
        $attrs = ['givenName' => ['Jane'], 'surname' => ['Doe']];
        self::assertSame('Jane Doe', SamlProvider::extractDisplayName($attrs));
    }

    public function testDisplayNameNullWhenAbsent(): void
    {
        self::assertNull(SamlProvider::extractDisplayName([]));
    }

    public function testDefaultAttributeNamesMatchMetadataDefaults(): void
    {
        $names = SamlProvider::attributeNames();
        self::assertSame('email', $names['email']);
        self::assertSame('firstName', $names['first']);
        self::assertSame('lastName', $names['last']);
        self::assertSame('displayName', $names['display']);
    }
}
