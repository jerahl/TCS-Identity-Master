<?php

declare(strict_types=1);

namespace App\Service;

use App\Db;
use PDO;

/**
 * Editable content for the orientation-checklist variants. Each variant ("doc")
 * has a heading, an intro paragraph, and a body written in a small, safe markup:
 *
 *   ## Section heading          -> starts a new section
 *   - A checklist item          -> an item under the current section
 *   [label](https://example)    -> a link inside intro or an item
 *   {name} {username} {email} {school} {position} {start_date}  -> placeholders
 *
 * Content is stored in `notify_template` (migration 0014); when a row is absent
 * the built-in defaults() apply, so the feature works before anything is saved.
 *
 * The renderers (renderText / renderItemHtml / parseBody) are pure + static so
 * they can be unit-tested — importantly, they are the XSS boundary: all
 * operator-entered text is HTML-escaped and only http(s) links become anchors.
 */
final class NotifyTemplateService
{
    /** Valid checklist variants. */
    public const DOCS = ['new_teacher', 'non_instructional'];

    private ?PDO $pdo;

    public function __construct(?PDO $db = null)
    {
        $this->pdo = $db;
    }

    private function db(): PDO
    {
        return $this->pdo ??= Db::connect(Db::ROLE_APP);
    }

    /**
     * The stored content for a doc, or the built-in default when nothing is saved
     * (or the table doesn't exist yet). Always returns heading/intro/body.
     *
     * @return array{doc:string,heading:string,intro:string,body:string,updated_at:?string,updated_by:?string,is_default:bool}
     */
    public function get(string $doc): array
    {
        $doc = in_array($doc, self::DOCS, true) ? $doc : self::DOCS[0];
        try {
            $stmt = $this->db()->prepare('SELECT heading, intro, body, updated_at, updated_by FROM notify_template WHERE doc = :d');
            $stmt->execute([':d' => $doc]);
            $row = $stmt->fetch();
        } catch (\Throwable) {
            // Table not migrated yet, or the store is unreachable — fall back to
            // built-in defaults so a checklist still generates.
            $row = false;
        }

        if ($row === false) {
            $d = self::defaults()[$doc];
            return $d + ['doc' => $doc, 'updated_at' => null, 'updated_by' => null, 'is_default' => true];
        }
        return [
            'doc'        => $doc,
            'heading'    => (string) $row['heading'],
            'intro'      => (string) ($row['intro'] ?? ''),
            'body'       => (string) ($row['body'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
            'updated_by' => $row['updated_by'] ?? null,
            'is_default' => false,
        ];
    }

    /** Both variants, for the editor screen. */
    public function all(): array
    {
        return array_map(fn(string $doc) => $this->get($doc), self::DOCS);
    }

    /** Upsert a doc's content and audit it (entity 'config'). */
    public function save(string $doc, string $heading, string $intro, string $body, string $actor): void
    {
        if (!in_array($doc, self::DOCS, true)) {
            throw new \InvalidArgumentException("Unknown checklist doc '{$doc}'.");
        }
        $db = $this->db();
        $before = $this->get($doc);
        $db->prepare(
            'INSERT INTO notify_template (doc, heading, intro, body, updated_by)
             VALUES (:d, :h, :i, :b, :u)
             ON DUPLICATE KEY UPDATE heading = VALUES(heading), intro = VALUES(intro),
                                     body = VALUES(body), updated_by = VALUES(updated_by)'
        )->execute([':d' => $doc, ':h' => $heading, ':i' => $intro, ':b' => $body, ':u' => $actor]);

        (new AuditService($db))->log('config', null, 'update',
            ['doc' => $doc, 'heading' => $before['heading']],
            ['doc' => $doc, 'heading' => $heading],
            $actor);
    }

    /** Revert a doc to its built-in default (delete the override). Audited. */
    public function reset(string $doc, string $actor): void
    {
        if (!in_array($doc, self::DOCS, true)) {
            throw new \InvalidArgumentException("Unknown checklist doc '{$doc}'.");
        }
        $db = $this->db();
        $db->prepare('DELETE FROM notify_template WHERE doc = :d')->execute([':d' => $doc]);
        (new AuditService($db))->log('config', null, 'update',
            ['doc' => $doc, 'action' => 'reset'], ['doc' => $doc, 'reset_to' => 'default'], $actor);
    }

    // ---- pure rendering (the XSS boundary; unit-tested) ---------------------

    /**
     * Parse the body markup into sections. Lines starting with '## ' open a
     * section; lines starting with '- ' add an item to the current section; other
     * non-blank lines are appended to the current section as items too (lenient).
     *
     * @return array<int,array{heading:string,items:string[]}>
     */
    public static function parseBody(string $body): array
    {
        $sections = [];
        $cur = null;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            if (str_starts_with($t, '## ')) {
                if ($cur !== null) {
                    $sections[] = $cur;
                }
                $cur = ['heading' => trim(substr($t, 3)), 'items' => []];
                continue;
            }
            $item = str_starts_with($t, '- ') ? trim(substr($t, 2)) : $t;
            if ($cur === null) {
                $cur = ['heading' => '', 'items' => []];
            }
            $cur['items'][] = $item;
        }
        if ($cur !== null) {
            $sections[] = $cur;
        }
        return $sections;
    }

    /**
     * Substitute {placeholders} then HTML-escape — for plain text (heading/intro
     * lines with no links).
     *
     * @param array<string,string> $vars
     */
    public static function renderText(string $raw, array $vars = []): string
    {
        return htmlspecialchars(self::substitute($raw, $vars), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Render one item to safe HTML: substitute placeholders, escape all text, and
     * turn `[label](url)` into an anchor — but only for http/https URLs (anything
     * else is left as escaped literal text, so a `javascript:` URL can't slip
     * through).
     *
     * @param array<string,string> $vars
     */
    public static function renderItemHtml(string $raw, array $vars = []): string
    {
        $text = self::substitute($raw, $vars);
        $out = '';
        $offset = 0;
        if (preg_match_all('/\[([^\]]+)\]\(([^)\s]+)\)/', $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $whole) {
                $start = $whole[1];
                $out .= htmlspecialchars(substr($text, $offset, $start - $offset), ENT_QUOTES, 'UTF-8');
                $label = $matches[1][$i][0];
                $url = $matches[2][$i][0];
                $out .= self::anchor($url, $label);
                $offset = $start + strlen($whole[0]);
            }
        }
        $out .= htmlspecialchars(substr($text, $offset), ENT_QUOTES, 'UTF-8');
        return $out;
    }

    /** An anchor for a safe (http/https) URL, else the escaped literal markup. */
    private static function anchor(string $url, string $label): string
    {
        $safe = preg_match('#^https?://#i', $url) === 1;
        $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if (!$safe) {
            // Leave the original markup as escaped text — never emit the link.
            return htmlspecialchars('[' . $label . '](' . $url . ')', ENT_QUOTES, 'UTF-8');
        }
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $labelHtml . '</a>';
    }

    /** @param array<string,string> $vars */
    private static function substitute(string $raw, array $vars): string
    {
        if ($vars === []) {
            return $raw;
        }
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = $v;
        }
        return strtr($raw, $map);
    }

    /**
     * Built-in default content for each variant (used until an editor saves).
     *
     * @return array<string,array{heading:string,intro:string,body:string}>
     */
    public static function defaults(): array
    {
        return [
            'new_teacher' => [
                'heading' => 'New Teacher Technology Orientation Checklist',
                'intro'   => 'Welcome to Tuscaloosa City Schools, {name}. Complete the steps below to activate and secure your accounts. Check each item off as you go.',
                'body'    => <<<'MD'
                ## First sign-in
                - Sign in to your district account at [portal.office.com](https://portal.office.com) using the username and email above and the temporary password provided by your school.
                - Set a permanent password and register for self-service password reset at [aka.ms/sspr](https://aka.ms/sspr).
                - Turn on multi-factor authentication when prompted.
                ## Instructional systems
                - Log in to PowerSchool (gradebook & attendance) at [your PowerSchool portal](https://powerschool.tuscaloosacityschools.com).
                - Log in to Google Workspace (Classroom, Drive) at [classroom.google.com](https://classroom.google.com) with your district email.
                - Access your district email & calendar in Outlook.
                - Complete the required technology acceptable-use and data-privacy trainings.
                ## Getting help
                - Submit a help-desk ticket at [help.tuscaloosacityschools.com](https://help.tuscaloosacityschools.com) or contact your school's technology contact.
                MD,
            ],
            'non_instructional' => [
                'heading' => 'Non-Instructional Employee Technology Orientation Checklist',
                'intro'   => 'Welcome to Tuscaloosa City Schools, {name}. Complete the steps below to activate and secure your accounts. Check each item off as you go.',
                'body'    => <<<'MD'
                ## First sign-in
                - Sign in to your district account at [portal.office.com](https://portal.office.com) using the username and email above and the temporary password provided by your supervisor.
                - Set a permanent password and register for self-service password reset at [aka.ms/sspr](https://aka.ms/sspr).
                - Turn on multi-factor authentication when prompted.
                ## District systems
                - Access your district email & calendar in Outlook.
                - Log in to the employee self-service portal for pay, benefits, and leave.
                - Complete the required technology acceptable-use and data-privacy trainings.
                ## Getting help
                - Submit a help-desk ticket at [help.tuscaloosacityschools.com](https://help.tuscaloosacityschools.com) or contact your department's technology contact.
                MD,
            ],
        ];
    }
}
