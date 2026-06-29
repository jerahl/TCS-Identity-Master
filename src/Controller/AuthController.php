<?php

declare(strict_types=1);

namespace App\Controller;

use App\Auth\SamlProvider;
use App\Service\AuthService;
use App\Support\Csrf;
use App\View\View;

/**
 * Sign-in flow: SSO via SAML against the district IdP, or (non-production only,
 * when SAML isn't configured) a dev login so the app is usable locally. Login
 * and logout are audited by AuthService.
 */
final class AuthController extends Controller
{
    public function loginPage(): string
    {
        if ($this->auth()->isAuthenticated()) {
            return $this->redirect(url('/'));
        }
        // Bare page (no app chrome) — user isn't authenticated yet.
        return View::partial('auth/login', [
            'samlConfigured' => $this->auth()->isSamlConfigured(),
            'devAllowed'     => $this->auth()->devLoginAllowed(),
            'csrf'           => Csrf::token(),
            'flash'          => $this->takeFlashPublic(),
        ]);
    }

    /** Kick off SAML SSO (redirect to IdP). */
    public function samlLogin(): string
    {
        if (!$this->auth()->isSamlConfigured()) {
            $this->flash('SSO is not configured.');
            return $this->redirect(url('/login'));
        }
        try {
            (new SamlProvider())->login(url('/'));
        } catch (\Throwable $e) {
            // e.g. missing SP config or php-saml not installed — surface a logged
            // reason and a friendly message instead of a raw 500 (mirrors acs()).
            error_log('[idm] SAML login: ' . $e->getMessage());
            \App\Support\SamlLog::failure('login', $e);
            $this->flash('Could not start single sign-on. Contact IT if this persists.');
            return $this->redirect(url('/login'));
        }
        return ''; // SamlProvider issued a redirect to the IdP
    }

    /** SAML assertion consumer service. */
    public function acs(): string
    {
        try {
            $attrs = (new SamlProvider())->acs();
        } catch (\Throwable $e) {
            error_log('[idm] SAML ACS: ' . $e->getMessage());
            // Reliable capture even when php-fpm worker error_log isn't in the
            // journal; records the reason (and, if SAML_DEBUG, the response).
            \App\Support\SamlLog::failure('acs', $e, $_POST['SAMLResponse'] ?? null);
            $this->flash('Single sign-on failed. Contact IT if this persists.');
            return $this->redirect(url('/login'));
        }

        $user = $this->auth()->findOrCreateUser($attrs['nameId'], $attrs['email'], $attrs['displayName']);
        if ($user === null) {
            $this->flash('Your account is deactivated. Contact an administrator.');
            return $this->redirect(url('/login'));
        }
        $this->auth()->login($user);
        return $this->redirect(url('/'));
    }

    /** SP metadata for the IdP admin. */
    public function metadata(): string
    {
        header('Content-Type: application/samlmetadata+xml');
        try {
            return (new SamlProvider())->metadata();
        } catch (\Throwable $e) {
            error_log('[idm] SAML metadata: ' . $e->getMessage());
            http_response_code(500);
            // Return a well-formed XML document (a comment-only body is invalid
            // XML and renders as a confusing parser error in the browser).
            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<error>SAML SP metadata unavailable. Check SAML_SP_* configuration. '
                . 'See the server log for details.</error>';
        }
    }

    /** Dev login (non-production, no SAML). Lets you pick a role to test RBAC. */
    public function devLogin(): string
    {
        if (!$this->auth()->devLoginAllowed()) {
            http_response_code(403);
            $this->flash('Dev login is disabled.');
            return $this->redirect(url('/login'));
        }
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/login'));
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'readonly');
        if ($email === '') {
            $this->flash('Enter an email.');
            return $this->redirect(url('/login'));
        }
        $user = $this->auth()->provisionDev($email, $role, $_POST['name'] ?? null);
        $this->auth()->login($user);
        return $this->redirect(url('/'));
    }

    public function logout(): string
    {
        $this->auth()->logout();
        return $this->redirect(url('/login'));
    }

    /** Flash read for the bare login page (no full render path). */
    private function takeFlashPublic(): ?string
    {
        $msg = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $msg;
    }
}
