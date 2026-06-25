<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PersonService;
use App\View\View;

/**
 * Base controller: assembles the shared layout data (active nav, breadcrumb,
 * review-queue badge, signed-in user) every page needs, then renders.
 */
abstract class Controller
{
    protected PersonService $people;

    public function __construct(?PersonService $people = null)
    {
        $this->people = $people ?? new PersonService();
    }

    /**
     * Render a template inside the layout with shared chrome data merged in.
     */
    protected function render(
        string $template,
        array $vars,
        string $activeNav,
        string $crumb,
        string $title
    ): string {
        $layout = [
            'title'       => $title,
            'activeNav'   => $activeNav,
            'crumb'       => $crumb,
            'queueCount'  => $this->safeQueueCount(),
            'searchQuery' => (string) ($_GET['q'] ?? ''),
            'currentUser' => $this->currentUser(),
            'flash'       => $this->takeFlash(),
        ];

        return View::page($template, $vars, $layout);
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

    /** Post/Redirect/Get helper. Returns '' so callers can `return $this->redirect(...)`. */
    protected function redirect(string $to): string
    {
        header('Location: ' . $to, true, 303);
        return '';
    }

    private function safeQueueCount(): int
    {
        try {
            return $this->people->pendingReviewCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Placeholder identity until SAML SSO lands in Milestone 7. Reflected in the
     * top bar so it's clear no real auth is in force yet.
     */
    protected function currentUser(): array
    {
        return [
            'name'     => 'Dev session',
            'role'     => 'No SSO yet (M7)',
            'initials' => 'DS',
        ];
    }
}
