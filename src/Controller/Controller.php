<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuthService;
use App\Service\PersonService;
use App\Service\ReviewService;
use App\View\View;

/**
 * Base controller: assembles shared layout data (active nav, breadcrumb,
 * review-queue badge, signed-in user, capability flags) and renders. Capability
 * flags (canEdit/canAdmin) are merged into BOTH the page vars and the layout so
 * templates can hide actions the user can't perform (server-side RBAC still
 * enforces it — hiding is courtesy, not security).
 */
abstract class Controller
{
    protected PersonService $people;
    private ?AuthService $authService = null;

    public function __construct(?PersonService $people = null)
    {
        $this->people = $people ?? new PersonService();
    }

    protected function auth(): AuthService
    {
        return $this->authService ??= new AuthService();
    }

    protected function render(
        string $template,
        array $vars,
        string $activeNav,
        string $crumb,
        string $title
    ): string {
        $shared = [
            'canEdit'     => $this->auth()->can('edit'),
            'canAdmin'    => $this->auth()->can('admin'),
            'currentUser' => $this->currentUser(),
        ];
        $layout = $shared + [
            'title'       => $title,
            'activeNav'   => $activeNav,
            'crumb'       => $crumb,
            'queueCount'  => $this->safeQueueCount(),
            'disableFlagged' => $this->safeDisableFlaggedCount(),
            'searchQuery' => (string) ($_GET['q'] ?? ''),
            'flash'       => $this->takeFlash(),
        ];

        return View::page($template, $vars + $shared, $layout);
    }

    private function safeQueueCount(): int
    {
        try {
            return $this->people->pendingReviewCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** People flagged for disable review (drives the red nav badge). */
    private function safeDisableFlaggedCount(): int
    {
        try {
            return (new ReviewService())->disableCandidateCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Display identity for the top bar, derived from the session user. */
    protected function currentUser(): array
    {
        $u = $this->auth()->user();
        if ($u === null) {
            return ['name' => 'Guest', 'role' => '—', 'initials' => '?'];
        }
        $name = (string) ($u['display_name'] ?: $u['email']);
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? (string) end($parts) : '';
        $initials = strtoupper(mb_substr($first, 0, 1) . mb_substr($last, 0, 1));
        if ($initials === '') {
            $initials = strtoupper(mb_substr((string) $u['email'], 0, 2));
        }
        return ['name' => $name, 'role' => ucfirst((string) $u['role']), 'initials' => $initials];
    }

    /** Store a one-shot message shown as a toast after the next render. */
    protected function flash(string $message): void
    {
        $_SESSION['flash'] = $message;
    }

    private function takeFlash(): ?string
    {
        $msg = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $msg;
    }

    /** Post/Redirect/Get helper. */
    protected function redirect(string $to): string
    {
        header('Location: ' . $to, true, 303);
        return '';
    }
}
