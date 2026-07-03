<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiKeyService;
use App\Support\Csrf;

/**
 * Self-service API key management ("Settings / API keys"). Any authenticated user
 * can mint and revoke keys for THEIR OWN account — a key inherits the owner's role,
 * so nobody can grant themselves more than they already have. Admins additionally
 * see all keys across users for oversight. The raw key is shown exactly once,
 * right after creation.
 */
final class ApiKeyController extends Controller
{
    private ApiKeyService $keys;

    public function __construct(?ApiKeyService $keys = null)
    {
        parent::__construct();
        $this->keys = $keys ?? new ApiKeyService();
    }

    public function index(): string
    {
        $userId = (int) ($this->auth()->user()['user_id'] ?? 0);

        // A freshly-minted key is stashed in the session for a one-time reveal.
        $newKey = $_SESSION['new_api_key'] ?? null;
        unset($_SESSION['new_api_key']);

        return $this->render('settings/api_keys', [
            'keys'    => $this->keys->listForUser($userId),
            'allKeys' => $this->auth()->can('admin') ? $this->keys->listAll() : null,
            'newKey'  => $newKey,
            'csrf'    => Csrf::token(),
        ], 'apikeys', 'Settings  /  API keys', 'API keys — TCS Identity Master');
    }

    public function create(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/settings/api-keys'));
        }
        $userId = (int) ($this->auth()->user()['user_id'] ?? 0);
        $email = (string) ($this->auth()->user()['email'] ?? '');
        $label = trim((string) ($_POST['label'] ?? ''));

        try {
            $created = $this->keys->create($userId, $label, $email);
            // One-time reveal on the next page load; never stored in plaintext.
            $_SESSION['new_api_key'] = [
                'plaintext' => $created['plaintext'],
                'label'     => $created['row']['label'] ?? $label,
            ];
            $this->flash('API key created — copy it now, it will not be shown again.');
        } catch (\Throwable $e) {
            error_log('[idm] api key create: ' . $e->getMessage());
            $this->flash('Could not create the API key.');
        }
        return $this->redirect(url('/settings/api-keys'));
    }

    public function revoke(): string
    {
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect(url('/settings/api-keys'));
        }
        $keyId = (int) ($_POST['key_id'] ?? 0);
        $userId = (int) ($this->auth()->user()['user_id'] ?? 0);
        $email = (string) ($this->auth()->user()['email'] ?? '');

        // Admins may revoke any key; everyone else only their own.
        $ownerScope = $this->auth()->can('admin') ? null : $userId;

        $ok = $this->keys->revoke($keyId, $ownerScope, $email);
        $this->flash($ok ? 'API key revoked.' : 'Key not found or already revoked.');
        return $this->redirect(url('/settings/api-keys'));
    }
}
