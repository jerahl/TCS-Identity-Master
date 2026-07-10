<?php

declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Service\SettingsService;
use App\Support\Csrf;

/**
 * Settings → Configuration: an admin-only page to edit the operationally-tunable
 * (non-secret) configuration — the Adaxes direct-provisioning knobs, OU/username
 * placement, and AD group names — without touching .env. Backed by
 * SettingsService (whitelist + app_setting); every save is CSRF-checked and
 * audited. Secrets stay in .env and are not shown here.
 */
final class SettingsController extends Controller
{
    private SettingsService $settings;

    public function __construct(?SettingsService $settings = null)
    {
        parent::__construct();
        $this->settings = $settings ?? new SettingsService();
    }

    public function index(): string
    {
        $stored = $this->safeStored();

        // Build display rows: effective value (post-override), the stored value,
        // whether a real env var pins it, and the field metadata.
        $groups = [];
        foreach (SettingsService::SCHEMA as $group) {
            $fields = [];
            foreach ($group['fields'] as $f) {
                $key = (string) $f['key'];
                $fields[] = $f + [
                    'value'     => (string) (Config::get($key, '') ?? ''),
                    'stored'    => $stored[$key] ?? null,
                    'envLocked' => Config::isEnvLocked($key),
                ];
            }
            $groups[] = ['title' => $group['title'], 'help' => $group['help'], 'fields' => $fields];
        }

        return $this->render('settings/config', [
            'groups'      => $groups,
            'storeReady'  => $this->storeReady(),
            'csrf'        => Csrf::token(),
        ], 'settings', 'Configuration  /  Settings', 'Configuration — TCS Identity Master');
    }

    /** Whether the app_setting table exists (false = migrations not run yet). */
    private function storeReady(): bool
    {
        try {
            $this->settings->stored();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function save(): string
    {
        $back = url('/settings/config');
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }

        try {
            $changed = $this->settings->save($_POST, $this->currentUser()['name']);
        } catch (\Throwable $e) {
            $msg = self::isMissingTable($e)
                ? 'The settings store is not set up yet — run database migrations (php bin/migrate.php) to create the app_setting table, then try again.'
                : 'Could not save settings: ' . $e->getMessage();
            $this->flash($msg);
            return $this->redirect($back);
        }

        $this->flash($changed === 0 ? 'No changes to save.' : "Saved {$changed} setting(s).");
        return $this->redirect($back);
    }

    /** A "base table not found" (unrun migration) error, so we can guide the operator. */
    private static function isMissingTable(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), "doesn't exist")
            || str_contains($e->getMessage(), '42S02')
            || str_contains($e->getMessage(), '1146');
    }

    /** @return array<string,string> */
    private function safeStored(): array
    {
        try {
            return $this->settings->stored();
        } catch (\Throwable) {
            return [];
        }
    }
}
