<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReviewService;
use App\Support\Csrf;

/**
 * Review queue: list pending cases, show the side-by-side comparison, and apply
 * confirm/reject decisions. Confirm/reject are POST + CSRF + Post/Redirect/Get.
 *
 * RBAC (editor/admin only) is enforced in Milestone 7; the actor is a placeholder
 * until SAML SSO lands.
 */
final class ReviewController extends Controller
{
    private ReviewService $review;

    public function __construct(?ReviewService $review = null)
    {
        parent::__construct();
        $this->review = $review ?? new ReviewService();
    }

    public function index(): string
    {
        $cases = $this->review->pendingCases();

        $selected = isset($_GET['case']) ? (int) $_GET['case'] : ($cases[0]['staging_id'] ?? 0);
        $detail = $selected > 0 ? $this->review->caseDetail($selected) : null;
        // If the requested case is gone (resolved), fall back to the first pending.
        if ($detail === null && $cases !== []) {
            $selected = (int) $cases[0]['staging_id'];
            $detail = $this->review->caseDetail($selected);
        }

        return $this->render('review/index', [
            'cases'    => $cases,
            'selected' => $selected,
            'detail'   => $detail,
            'csrf'     => Csrf::token(),
        ], 'review', 'Review queue', 'Review queue — TCS Identity Master');
    }

    public function confirm(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            return $this->fail('Invalid session token — please retry.');
        }
        $stagingId = (int) ($_POST['staging_id'] ?? 0);
        $personId = (int) ($_POST['candidate_person_id'] ?? 0);
        if ($stagingId <= 0 || $personId <= 0) {
            return $this->fail('Missing decision parameters.');
        }
        try {
            $name = $this->review->confirm($stagingId, $personId, $this->actor());
            $this->flash("Linked to {$name} — no duplicate account created.");
        } catch (\Throwable $e) {
            error_log('[idm] review confirm: ' . $e->getMessage());
            $this->flash('Could not confirm the match — it may have already been resolved.');
        }
        return $this->redirect(url('/review'));
    }

    public function reject(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            return $this->fail('Invalid session token — please retry.');
        }
        $stagingId = (int) ($_POST['staging_id'] ?? 0);
        if ($stagingId <= 0) {
            return $this->fail('Missing decision parameters.');
        }
        try {
            $name = $this->review->reject($stagingId, $this->actor());
            $this->flash("{$name} created as a new golden record.");
        } catch (\Throwable $e) {
            error_log('[idm] review reject: ' . $e->getMessage());
            $this->flash('Could not create a new record — the case may have already been resolved.');
        }
        return $this->redirect(url('/review'));
    }

    private function fail(string $message): string
    {
        $this->flash($message);
        return $this->redirect(url('/review'));
    }

    /** Decision actor. Becomes the SAML user in Milestone 7. */
    private function actor(): string
    {
        return $this->currentUser()['name'];
    }
}
