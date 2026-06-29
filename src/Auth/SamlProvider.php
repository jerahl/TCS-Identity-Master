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
        // SP metadata depends only on SP config. The IdP admin needs this file
        // BEFORE the IdP side is set up, so build SP-only settings here — don't
        // require IdP config that legitimately isn't configured yet. The second
        // arg ($spValidationOnly) tells php-saml to skip IdP validation too.
        $settings = new SamlSettings($this->settings(true), true);
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

    /**
     * Build the onelogin settings array from config.
     *
     * @param bool $spOnly When true (SP metadata generation), IdP fields are
     *   optional — they may not be configured yet. For login/ACS leave it false
     *   so missing IdP config fails loudly instead of producing a broken flow.
     */
    private function settings(bool $spOnly = false): array
    {
        $spKey = self::fileContents(Config::get('SAML_SP_PRIVATE_KEY_FILE'));
        $spCert = self::fileContents(Config::get('SAML_SP_CERT_FILE'));

        // IdP values are required for SSO, but optional when we only need SP metadata.
        $idp = static fn(string $key): string => $spOnly
            ? (string) Config::get($key, '')
            : Config::require($key);

        // IdP signing cert: an inline base64 value (SAML_IDP_X509_CERT) OR a PEM
        // file (SAML_IDP_X509_CERT_FILE). The file form avoids the fragile
        // single-line-base64-in-.env trap that yields "Unable to extract public
        // key". php-saml's formatCert tolerates PEM headers and line breaks.
        $idpCert = (string) Config::get('SAML_IDP_X509_CERT', '');
        if ($idpCert === '') {
            $idpCert = (string) (self::fileContents(Config::get('SAML_IDP_X509_CERT_FILE')) ?? '');
        }
        if (!$spOnly && trim($idpCert) === '') {
            throw new RuntimeException('Missing required config: SAML_IDP_X509_CERT (or SAML_IDP_X509_CERT_FILE)');
        }

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
                'entityId' => $idp('SAML_IDP_ENTITY_ID'),
                'singleSignOnService' => [
                    'url' => $idp('SAML_IDP_SSO_URL'),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => (string) Config::get('SAML_IDP_SLO_URL', ''),
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $idpCert,
            ],
            'security' => [
                'requestedAuthnContext' => false,
                // Require a valid signature on the response, OR on the assertion.
                // Default to message (response) signing: ClassLink — and many IdPs
                // — sign the enveloping <Response> (which cryptographically covers
                // the assertion) rather than the assertion element itself. An IdP
                // that signs the assertion instead can flip these via .env.
                'wantMessagesSigned' => Config::bool('SAML_WANT_MESSAGES_SIGNED', true),
                'wantAssertionsSigned' => Config::bool('SAML_WANT_ASSERTIONS_SIGNED', false),
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
