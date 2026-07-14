<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Admin-editable subject/body for the emails IDM sends (the rename workflow's
 * notice / confirmation / reminder / removed messages). Stored in email_template
 * (migration 0020); when a key has no row the built-in default() applies, so the
 * emails work before anything is saved. Bodies use {placeholder} tokens, listed
 * per template, substituted at send time.
 *
 * render() is pure and unit-tested — it is where operator-entered text meets the
 * runtime values, so the placeholder substitution is deterministic and total
 * (an unknown token is left as literal text, never a fatal).
 */
final class EmailTemplateService
{
    /**
     * Template metadata for the editor: key => [label, description, placeholders].
     *
     * @var array<string,array{label:string,description:string,placeholders:list<string>}>
     */
    public const TEMPLATES = [
        'rename_notice' => [
            'label'        => 'Rename — upcoming-change notice',
            'description'  => 'Sent when a rename is approved. Goes to the employee (old address), their principal, and IT.',
            'placeholders' => ['name', 'old_name', 'old_username', 'new_username', 'old_email', 'new_email', 'cutover_date', 'days_remaining', 'alias_days'],
        ],
        'rename_done' => [
            'label'        => 'Rename — change complete',
            'description'  => 'Sent at cutover once the username/email have changed. Goes to the new address, principal, and IT.',
            'placeholders' => ['name', 'old_username', 'new_username', 'old_email', 'new_email', 'alias_days'],
        ],
        'alias_reminder' => [
            'label'        => 'Alias — removal reminder',
            'description'  => 'Sent before the old email alias is removed. Goes to the principal and IT.',
            'placeholders' => ['name', 'old_email', 'new_email', 'remove_date', 'days_remaining'],
        ],
        'alias_removed' => [
            'label'        => 'Alias — removed',
            'description'  => 'Sent after the old email alias is removed. Goes to the principal and IT.',
            'placeholders' => ['name', 'old_email', 'new_email'],
        ],
    ];

    private ?PDO $pdo;

    public function __construct(?PDO $db = null)
    {
        $this->pdo = $db;
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    /** The built-in default subject/body per key. */
    public static function defaults(): array
    {
        return [
            'rename_notice' => [
                'subject' => 'Upcoming username & email change for {name}',
                'body' =>
                    "The employee name has been changed from {old_name} to {name}.\n\n"
                    . "In {days_remaining} days, on {cutover_date}, the username and email address will change:\n"
                    . "  username:  {old_username}  ->  {new_username}\n"
                    . "  email:     {old_email}  ->  {new_email}\n\n"
                    . "Mail sent to the old address ({old_email}) will continue to be delivered for "
                    . "{alias_days} days after the change, then that alias will be removed. "
                    . "Reminders will be sent before removal.\n\n"
                    . "No action is required. — TCS Identity Management\n",
            ],
            'rename_done' => [
                'subject' => 'Username & email changed for {name}',
                'body' =>
                    "The change is complete:\n"
                    . "  username: {old_username} -> {new_username}\n"
                    . "  email:    {old_email} -> {new_email}\n\n"
                    . "Mail to {old_email} will keep delivering for {alias_days} days, then that alias is removed.\n",
            ],
            'alias_reminder' => [
                'subject' => 'Reminder: email alias {old_email} will be removed on {remove_date}',
                'body' =>
                    "The forwarding alias {old_email} is scheduled for removal in {days_remaining} days, "
                    . "on {remove_date}. After that, mail to the old address will bounce.\n",
            ],
            'alias_removed' => [
                'subject' => 'Email alias removed: {old_email}',
                'body' =>
                    "The forwarding alias {old_email} has been removed. "
                    . "Mail to that address will no longer be delivered.\n",
            ],
        ];
    }

    /**
     * The stored subject/body for a key, or the built-in default. Falls back to
     * default when the table is missing (not migrated / no DB).
     *
     * @return array{key:string,subject:string,body:string,updated_at:?string,updated_by:?string,is_default:bool}
     */
    public function get(string $key): array
    {
        $key = isset(self::TEMPLATES[$key]) ? $key : array_key_first(self::TEMPLATES);
        try {
            $stmt = $this->db()->prepare('SELECT subject, body, updated_at, updated_by FROM email_template WHERE template_key = :k');
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            $row = false;
        }

        if ($row === false) {
            $d = self::defaults()[$key];
            return ['key' => $key, 'subject' => $d['subject'], 'body' => $d['body'], 'updated_at' => null, 'updated_by' => null, 'is_default' => true];
        }
        return [
            'key'        => $key,
            'subject'    => (string) $row['subject'],
            'body'       => (string) $row['body'],
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'is_default' => false,
        ];
    }

    /** Every template (for the editor), in declaration order. @return list<array<string,mixed>> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::TEMPLATES) as $key) {
            $out[] = $this->get($key) + self::TEMPLATES[$key];
        }
        return $out;
    }

    /**
     * Render a template with the given variables: returns the substituted
     * subject + body. Unknown keys are left as-is; unused vars are ignored.
     *
     * @param array<string,string|int> $vars
     * @return array{subject:string, body:string}
     */
    public function render(string $key, array $vars): array
    {
        $t = $this->get($key);
        return ['subject' => self::substitute($t['subject'], $vars), 'body' => self::substitute($t['body'], $vars)];
    }

    /** Pure {placeholder} substitution. @param array<string,string|int> $vars */
    public static function substitute(string $template, array $vars): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars): string {
            $key = $m[1];
            return array_key_exists($key, $vars) ? (string) $vars[$key] : $m[0];
        }, $template);
    }

    /** Upsert a template's subject/body and audit it. Portable (MySQL + sqlite). */
    public function save(string $key, string $subject, string $body, string $actor): void
    {
        if (!isset(self::TEMPLATES[$key])) {
            throw new \InvalidArgumentException("Unknown email template '{$key}'.");
        }
        $before = $this->get($key);
        $exists = $this->db()->prepare('SELECT 1 FROM email_template WHERE template_key = :k');
        $exists->execute([':k' => $key]);
        if ($exists->fetchColumn() !== false) {
            $this->db()->prepare('UPDATE email_template SET subject = :s, body = :b, updated_by = :u WHERE template_key = :k')
                ->execute([':s' => $subject, ':b' => $body, ':u' => $actor, ':k' => $key]);
        } else {
            $this->db()->prepare('INSERT INTO email_template (template_key, subject, body, updated_by) VALUES (:k, :s, :b, :u)')
                ->execute([':k' => $key, ':s' => $subject, ':b' => $body, ':u' => $actor]);
        }
        (new AuditService($this->db()))->log('config', null, 'update',
            ['email_template' => $key, 'subject' => $before['subject']],
            ['email_template' => $key, 'subject' => $subject], $actor);
    }

    /** Revert a template to its built-in default (delete the override). Audited. */
    public function reset(string $key, string $actor): void
    {
        if (!isset(self::TEMPLATES[$key])) {
            throw new \InvalidArgumentException("Unknown email template '{$key}'.");
        }
        $this->db()->prepare('DELETE FROM email_template WHERE template_key = :k')->execute([':k' => $key]);
        (new AuditService($this->db()))->log('config', null, 'update',
            ['email_template' => $key, 'action' => 'reset'], ['email_template' => $key, 'reset_to' => 'default'], $actor);
    }
}
