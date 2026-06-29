<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\SamlProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Regression test for the SP-metadata endpoint.
 *
 * SP metadata must be generatable BEFORE the IdP is configured — the IdP admin
 * needs our metadata to set their side up. Previously SamlProvider::settings()
 * called Config::require() on IdP keys unconditionally, so /saml/metadata threw
 * and emitted an invalid (comment-only) XML body. settings(true) must now build
 * SP-only settings without requiring IdP config, while the login/ACS path
 * (settings(false)) must still fail loudly when IdP config is missing.
 *
 * Uses reflection so the test does not require onelogin/php-saml to be installed
 * (the constructor's class_exists guard is bypassed; settings() itself only
 * builds an array).
 */
final class SamlMetadataTest extends TestCase
{
    /** @var list<string> */
    private array $spKeys = ['SAML_SP_ENTITY_ID', 'SAML_SP_ACS_URL', 'SAML_SP_SLS_URL', 'APP_BASE_URL'];
    /** @var list<string> */
    private array $idpKeys = ['SAML_IDP_ENTITY_ID', 'SAML_IDP_SSO_URL', 'SAML_IDP_X509_CERT'];

    protected function setUp(): void
    {
        // SP side configured (as it would be in production)...
        putenv('SAML_SP_ENTITY_ID=https://identity.example.test/saml/metadata');
        putenv('SAML_SP_ACS_URL=https://identity.example.test/saml/acs');
        putenv('SAML_SP_SLS_URL=https://identity.example.test/saml/sls');
        putenv('APP_BASE_URL=https://identity.example.test');
        // ...IdP side deliberately NOT configured.
        foreach ($this->idpKeys as $k) {
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ([...$this->spKeys, ...$this->idpKeys] as $k) {
            putenv($k);
        }
    }

    private function invokeSettings(bool $spOnly): array
    {
        $ref = new ReflectionClass(SamlProvider::class);
        $obj = $ref->newInstanceWithoutConstructor();
        $m = $ref->getMethod('settings');
        $m->setAccessible(true);
        return $m->invoke($obj, $spOnly);
    }

    public function testSpMetadataSettingsDoNotRequireIdpConfig(): void
    {
        $settings = $this->invokeSettings(true);

        self::assertSame('https://identity.example.test/saml/metadata', $settings['sp']['entityId']);
        // IdP fields resolve to empty strings rather than throwing.
        self::assertSame('', $settings['idp']['entityId']);
        self::assertSame('', $settings['idp']['x509cert']);
    }

    public function testLoginSettingsStillRequireIdpConfig(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAML_IDP_ENTITY_ID');
        $this->invokeSettings(false);
    }

    /**
     * Default signature policy: require the message (response) signature, which
     * is what ClassLink sends and which covers the assertion. A regression here
     * would re-break SSO login with "Assertion … is not signed".
     */
    public function testDefaultSignaturePolicyRequiresMessageSignature(): void
    {
        $security = $this->invokeSettings(true)['security'];
        self::assertTrue($security['wantMessagesSigned'], 'message signature must be required by default');
        self::assertFalse($security['wantAssertionsSigned'], 'assertion signature must be optional by default');
    }
}
