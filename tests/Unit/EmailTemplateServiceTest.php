<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\EmailTemplateService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * EmailTemplateService — editable rename-email subject/body with built-in
 * defaults, {placeholder} substitution, and portable upsert/reset over sqlite.
 */
final class EmailTemplateServiceTest extends TestCase
{
    private function db(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('CREATE TABLE email_template (template_key TEXT PRIMARY KEY, subject TEXT, body TEXT, updated_by TEXT, updated_at TEXT)');
        $db->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY, entity TEXT, entity_id INTEGER, action TEXT,
            before_json TEXT, after_json TEXT, actor TEXT, at TEXT DEFAULT CURRENT_TIMESTAMP)');
        return $db;
    }

    public function testDefaultsApplyWhenNothingSaved(): void
    {
        $t = new EmailTemplateService($this->db());
        $notice = $t->get('rename_notice');
        self::assertTrue($notice['is_default']);
        self::assertStringContainsString('{new_username}', $notice['body']);
    }

    public function testSubstitutionFillsKnownTokensAndLeavesUnknown(): void
    {
        $out = EmailTemplateService::substitute('Hi {name}, {new_email} on {cutover_date}. {unknown}', [
            'name' => 'John Jones', 'new_email' => 'jjones@x', 'cutover_date' => '2026-07-17',
        ]);
        self::assertSame('Hi John Jones, jjones@x on 2026-07-17. {unknown}', $out);
    }

    public function testRenderUsesSavedOverride(): void
    {
        $db = $this->db();
        $t = new EmailTemplateService($db);
        $t->save('rename_notice', 'Name change: {name}', 'Your new email is {new_email}.', 'admin');

        $msg = $t->render('rename_notice', ['name' => 'John Jones', 'new_email' => 'jjones@tusc.k12.al.us']);
        self::assertSame('Name change: John Jones', $msg['subject']);
        self::assertSame('Your new email is jjones@tusc.k12.al.us.', $msg['body']);
        self::assertFalse($t->get('rename_notice')['is_default']);
    }

    public function testResetRevertsToDefault(): void
    {
        $db = $this->db();
        $t = new EmailTemplateService($db);
        $t->save('alias_removed', 'X', 'Y', 'admin');
        self::assertFalse($t->get('alias_removed')['is_default']);

        $t->reset('alias_removed', 'admin');
        self::assertTrue($t->get('alias_removed')['is_default']);
    }

    public function testSaveRejectsUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EmailTemplateService($this->db()))->save('not_a_template', 's', 'b', 'admin');
    }

    public function testAllReturnsEveryTemplateWithMetadata(): void
    {
        $all = (new EmailTemplateService($this->db()))->all();
        self::assertCount(count(EmailTemplateService::TEMPLATES), $all);
        self::assertArrayHasKey('placeholders', $all[0]);
        self::assertArrayHasKey('label', $all[0]);
    }
}
