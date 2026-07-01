<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Csrf;
use App\Sync\GoogleProvisioner;

/**
 * Per-person direct-to-Google actions from the record page: link an existing
 * account, create, push golden-record changes, suspend (disable) and restore.
 * All are editor+ (gated in public/index.php), CSRF-checked POSTs that redirect
 * back to the person detail with a flash — no inline JS, CSP-safe, mirroring
 * PersonController::disable().
 *
 * The heavy lifting (correlate -> Google write -> reflect into the crosswalk,
 * account_sync_status, audit + timeline) lives in GoogleProvisioner so the batch
 * GoogleSync behaves identically.
 */
final class GoogleController extends Controller
{
    private const ACTIONS = ['link', 'create', 'push', 'suspend', 'restore'];

    public function act(array $params): string
    {
        $id = (int) ($params['id'] ?? 0);
        $action = (string) ($params['action'] ?? '');
        $back = url('/people/' . $id) . '#google';

        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }
        if (!in_array($action, self::ACTIONS, true)) {
            $this->flash('Unknown Google action.');
            return $this->redirect($back);
        }
        if ($id <= 0) {
            $this->flash('That person no longer exists.');
            return $this->redirect($back);
        }

        try {
            $res = (new GoogleProvisioner())->provision($id, $action, $this->currentUser()['name']);
            $this->flash($res['message']);
        } catch (\Throwable $e) {
            error_log('[idm] google ' . $action . ': ' . $e->getMessage());
            $this->flash('Google ' . $action . ' failed unexpectedly.');
        }
        return $this->redirect($back);
    }
}
