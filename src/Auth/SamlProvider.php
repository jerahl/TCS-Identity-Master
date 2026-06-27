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

    /**
     * The SAML attribute names this SP requests from ClassLink. ClassLink has no
     * fixed attribute names — the district admin maps ClassLink source fields
     * (Email / Given Name / Family Name / Display Name) to whatever names the SP
     * declares. These defaults are advertised in our SP metadata (so the admin
     * sees exactly what to map) AND used to read the assertion, so the two can't
     * drift. Override via env only if the admin must use different names.
     *
     * @return array{email:string,first:string,last:string,display:string}
     */
    public static function attributeNames(): array
    {
        return [
            'email'   => (string) Config::get('SAML_ATTR_EMAIL', 'email'),
            'first'   => (string) Config::get('SAML_ATTR_FIRST_NAME', 'firstName'),
            'last'    => (string) Config::get('SAML_ATTR_LAST_NAME', 'lastName'),
            'display' => (string) Config::get('SAML_ATTR_DISPLAY_NAME', 'displayName'),
        ];
    }

    /** Build the onelogin settings array from config. */
    private function settings(): array
    {
        $spKey = self::fileContents(Config::get('SAML_SP_PRIVATE_KEY_FILE'));
        $spCert = self::fileContents(Config::get('SAML_SP_CERT_FILE'));
        $attr = self::attributeNames();
        $spSls = trim((string) Config::get('SAML_SP_SLS_URL', ''));
        $idpSlo = trim((string) Config::get('SAML_IDP_SLO_URL', ''));

        $sp = [
            'entityId' => Config::require('SAML_SP_ENTITY_ID'),
            'assertionConsumerService' => [
                'url' => Config::require('SAML_SP_ACS_URL'),
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            ],
            'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => $spCert ?? '',
            'privateKey' => $spKey ?? '',
            // Advertised in SP metadata as <md:RequestedAttribute> so the
            // ClassLink admin knows precisely which attributes to map.
            'attributeConsumingService' => [
                'serviceName' => 'TCS Identity Master',
                'serviceDescription' => 'Staff/faculty identity administration',
                'requestedAttributes' => [
                    ['name' => $attr['email'],   'isRequired' => true],
                    ['name' => $attr['first'],   'isRequired' => false],
                    ['name' => $attr['last'],    'isRequired' => false],
                    ['name' => $attr['display'], 'isRequired' => false],
                ],
            ],
        ];
        // Single Logout is optional (ClassLink may not use it). Only declare it
        // when configured — an empty URL fails metadata validation.
        if ($spSls !== '') {
            $sp['singleLogoutService'] = [
                'url' => $spSls,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ];
        }

        $idp = [
            'entityId' => Config::require('SAML_IDP_ENTITY_ID'),
            'singleSignOnService' => [
                'url' => Config::require('SAML_IDP_SSO_URL'),
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ],
            'x509cert' => Config::require('SAML_IDP_X509_CERT'),
        ];
        if ($idpSlo !== '') {
            $idp['singleLogoutService'] = [
                'url' => $idpSlo,
                'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
            ];
        }

        return [
            'strict' => true,
            'baseurl' => Config::get('APP_BASE_URL'),
            'sp' => $sp,
            'idp' => $idp,
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
        // Our configured ClassLink attribute first, then common variants.
        $candidates = array_merge(
            [self::attributeNames()['email']],
            ['email', 'mail', 'emailAddress', 'Email', 'EmailAddress',
             'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress']
        );
        $v = self::firstAttr($attrs, $candidates);
        return $v ?? $nameId; // last resort: use NameID as the key
    }

    public static function extractDisplayName(array $attrs): ?string
    {
        $names = self::attributeNames();
        $display = self::firstAttr($attrs, array_merge(
            [$names['display']],
            ['displayName', 'name', 'cn',
             'http://schemas.microsoft.com/identity/claims/displayname']
        ));
        if ($display !== null) {
            return $display;
        }
        // Build it from first + last (ClassLink "Given Name" / "Family Name").
        $given = self::firstAttr($attrs, array_merge(
            [$names['first']],
            ['firstName', 'givenName', 'givenname', 'FirstName', 'GivenName',
             'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname']
        ));
        $sn = self::firstAttr($attrs, array_merge(
            [$names['last']],
            ['lastName', 'surname', 'sn', 'familyName', 'LastName', 'Surname',
             'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname']
        ));
        $full = trim((string) $given . ' ' . (string) $sn);
        return $full !== '' ? $full : null;
    }

    /**
     * First non-empty attribute value matching any of $keys, compared
     * case-insensitively (ClassLink admins type the attribute names by hand, so
     * case can vary between the mapping and the assertion).
     *
     * @param array<string,mixed> $attrs
     * @param string[]            $keys
     */
    private static function firstAttr(array $attrs, array $keys): ?string
    {
        $lower = [];
        foreach ($attrs as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        foreach ($keys as $key) {
            $hit = $lower[strtolower($key)] ?? null;
            if (is_array($hit) && isset($hit[0]) && trim((string) $hit[0]) !== '') {
                return (string) $hit[0];
            }
            if (is_string($hit) && trim($hit) !== '') {
                return $hit;
            }
        }
        return null;
    }
}
