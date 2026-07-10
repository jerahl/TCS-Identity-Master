<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\EmailTemplateService;
use App\Support\Csrf;

/**
 * Settings → Email templates: admin editing of the subject/body of the emails
 * IDM sends (the rename workflow's notice / confirmation / reminder / removed
 * messages). Backed by EmailTemplateService (email_template + built-in defaults).
 * Bodies use {placeholder} tokens; a per-template list is shown in the editor.
 * Saves are CSRF-checked and audited; "Reset" reverts to the built-in default.
 */
final class EmailTemplateController extends Controller
{
    private EmailTemplateService $templates;

    public function __construct(?EmailTemplateService $templates = null)
    {
        parent::__construct();
        $this->templates = $templates ?? new EmailTemplateService();
    }

    public function index(): string
    {
        return $this->render('settings/email_templates', [
            'templates' => $this->templates->all(),
            'csrf'      => Csrf::token(),
        ], 'settings', 'Configuration  /  Email templates', 'Email templates — TCS Identity Master');
    }

    public function save(): string
    {
        $back = url('/settings/email-templates');
        if (!Csrf::check($_POST['_csrf'] ?? null)) {
            $this->flash('Invalid session token — please retry.');
            return $this->redirect($back);
        }
        $key = (string) ($_POST['key'] ?? '');
        if (!isset(EmailTemplateService::TEMPLATES[$key])) {
            $this->flash('Unknown template.');
            return $this->redirect($back);
        }

        try {
            if (isset($_POST['reset'])) {
                $this->templates->reset($key, $this->currentUser()['name']);
                $this->flash('Reverted "' . EmailTemplateService::TEMPLATES[$key]['label'] . '" to the built-in default.');
            } else {
                $subject = trim((string) ($_POST['subject'] ?? ''));
                $body = (string) ($_POST['body'] ?? '');
                if ($subject === '' || trim($body) === '') {
                    $this->flash('Subject and body are both required.');
                    return $this->redirect($back);
                }
                $this->templates->save($key, $subject, $body, $this->currentUser()['name']);
                $this->flash('Saved "' . EmailTemplateService::TEMPLATES[$key]['label'] . '".');
            }
        } catch (\Throwable $e) {
            $this->flash('Could not save the template: ' . $e->getMessage());
        }
        return $this->redirect($back);
    }
}
