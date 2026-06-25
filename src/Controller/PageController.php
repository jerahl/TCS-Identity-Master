<?php

declare(strict_types=1);

namespace App\Controller;

use App\View\View;
use Throwable;

/**
 * Home redirect, placeholder pages for not-yet-built nav targets, and error pages.
 */
final class PageController extends Controller
{
    public function placeholder(string $heading, string $activeNav, string $crumb, string $feature, int $milestone): string
    {
        return $this->render('pages/placeholder', [
            'heading'   => $heading,
            'feature'   => $feature,
            'milestone' => $milestone,
        ], $activeNav, $crumb, $heading . ' — TCS Identity Master');
    }

    public function notFound(): string
    {
        http_response_code(404);
        return $this->render('pages/not_found', [
            'message' => 'That page does not exist.',
        ], '', 'Not found', 'Not found — TCS Identity Master');
    }

    /** Minimal error page (no layout/DB dependency — may be why we're here). */
    public function error(?Throwable $e): string
    {
        return View::partial('pages/error', ['error' => $e]);
    }
}
