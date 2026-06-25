<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Settings as SamlSettings;
use RuntimeException;

/**
 * Thin wrapper over onelogin/php-saml. All IdP/SP config comes from the
 * environment (never hardcoded). Used only when SAML is configured; the
 * controller falls back to dev login otherwise (non-production).
 */
final class SamlProvider
{
    public function __construct()
    {
        if (!class_exists(SamlAuth::class)) {
            throw new RuntimeException('onelogin/php-saml is not installed (composer require onelogin/php-saml).');
        }
    }

    /** Begin SSO: redirect the browser to the IdP. */
    public function login(?string $returnTo = null): void
    {
        $this->auth()->login($returnTo);
    }

    /**
     * Process the IdP's SAML response at the ACS endpoint.
     *
     * @return array{nameId:string,email:string,displayName:?string}
     */
    public function acs(): array
    {
        $auth = $this->auth();
        $auth->processResponse();

        $errors = $auth->getErrors();
        if ($errors !== []) {
            throw new RuntimeException('SAML error: ' . implode(', ', $errors) . ' — ' . $auth->getLastErrorReason());
        }
        if (!$auth->isAuthenticated()) {
            throw new RuntimeException('SAML authentication failed.');
        }

        $nameId = (string) $auth->getNameId();
        $attrs = $auth->getAttributes();

        return [
            'nameId'      => $nameId,
            'email'       => self::extractEmail($nameId, $attrs),
            'displayName' => self::extractDisplayName($attrs),
        ];
    }

    /** SP metadata XML (for handing to the IdP admin). */
    public function metadata(): string
    {
        $settings = new SamlSettings($this->settings(), true);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);
        if ($errors !== []) {
            throw new RuntimeException('Invalid SP metadata: ' . implode(', ', $errors));
        }
        return $metadata;
    }

    public function logout(?string $returnTo = null): void
    {
        $this->auth()->logout($returnTo);
    }

    private function auth(): SamlAuth
    {
        return new SamlAuth($this->settings());
    }

    /** Build the onelogin settings array from config. */
    private function settings(): array
    {
        $spKey = self::fileContents(Config::get('SAML_SP_PRIVATE_KEY_FILE'));
        $spCert = self::fileContents(Config::get('SAML_SP_CERT_FILE'));

        return [
            'strict' => true,
            'baseurl' => Config::get('APP_BASE_URL'),
            'sp' => [
                'entityId' => Config::require('SAML_SP_ENTITY_ID'),
                'assertionConsumerService' => [
                    'url' => Config::require('SAML_SP_ACS_URL'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => (string) Config::get('SAML_SP_SLS_URL', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
                'x509cert' => $spCert ?? '',
                'privateKey' => $spKey ?? '',
            ],
            'idp' => [
                'entityId' => Config::require('SAML_IDP_ENTITY_ID'),
                'singleSignOnService' => [
                    'url' => Config::require('SAML_IDP_SSO_URL'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => (string) Config::get('SAML_IDP_SLO_URL', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => Config::require('SAML_IDP_X509_CERT'),
            ],
            'security' => [
                'requestedAuthnContext' => false,
                'wantAssertionsSigned' => true,
            ],
        ];
    }

    private static function fileContents(?string $path): ?string
    {
        if ($path === null || !is_file($path) || !is_readable($path)) {
            return null;
        }
        return (string) file_get_contents($path);
    }

    /** Pull an email from the NameID (if email-format) or common attribute keys. */
    public static function extractEmail(string $nameId, array $attrs): string
    {
        if (filter_var($nameId, FILTER_VALIDATE_EMAIL)) {
            return $nameId;
        }
        foreach (['email', 'mail', 'emailAddress',
                  'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'] as $key) {
            if (!empty($attrs[$key][0])) {
                return (string) $attrs[$key][0];
            }
        }
        return $nameId; // last resort: use NameID as the key
    }

    public static function extractDisplayName(array $attrs): ?string
    {
        foreach (['displayName', 'name', 'cn',
                  'http://schemas.microsoft.com/identity/claims/displayname'] as $key) {
            if (!empty($attrs[$key][0])) {
                return (string) $attrs[$key][0];
            }
        }
        $given = $attrs['givenName'][0] ?? $attrs['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'][0] ?? null;
        $sn = $attrs['surname'][0] ?? $attrs['sn'][0] ?? $attrs['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'][0] ?? null;
        $full = trim((string) $given . ' ' . (string) $sn);
        return $full !== '' ? $full : null;
    }
}
