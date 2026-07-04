<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\NotifyTemplateService;
use PHPUnit\Framework\TestCase;

/**
 * The pure, DB-free rendering of the editable checklist markup — including the
 * XSS boundary: operator text is escaped and only http(s) links become anchors.
 */
final class NotifyTemplateServiceTest extends TestCase
{
    public function testParseBodySplitsSectionsAndItems(): void
    {
        $body = "## First\n- one\n- two\n## Second\n- three";
        $secs = NotifyTemplateService::parseBody($body);
        self::assertCount(2, $secs);
        self::assertSame('First', $secs[0]['heading']);
        self::assertSame(['one', 'two'], $secs[0]['items']);
        self::assertSame('Second', $secs[1]['heading']);
        self::assertSame(['three'], $secs[1]['items']);
    }

    public function testParseBodyIgnoresBlankLinesAndBareItems(): void
    {
        $body = "## S\n\n- a\nplain line\n";
        $secs = NotifyTemplateService::parseBody($body);
        self::assertSame(['a', 'plain line'], $secs[0]['items']);
    }

    public function testRenderTextSubstitutesAndEscapes(): void
    {
        $out = NotifyTemplateService::renderText('Hi {name} <b>', ['name' => 'A&B']);
        self::assertSame('Hi A&amp;B &lt;b&gt;', $out);
    }

    public function testRenderItemHtmlMakesHttpLinks(): void
    {
        $out = NotifyTemplateService::renderItemHtml('Go to [portal](https://p.example.com) now');
        self::assertSame('Go to <a href="https://p.example.com">portal</a> now', $out);
    }

    public function testRenderItemHtmlEscapesPlainText(): void
    {
        $out = NotifyTemplateService::renderItemHtml('a <script>alert(1)</script> b');
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testRenderItemHtmlRejectsJavascriptUrl(): void
    {
        // A javascript: URL must never become an anchor — it stays inert, escaped
        // text (no <a>, no href), which is the safe outcome.
        $out = NotifyTemplateService::renderItemHtml('click [here](javascript:alert(1))');
        self::assertStringNotContainsString('<a ', $out);
        self::assertStringNotContainsString('href=', $out);
    }

    public function testRenderItemHtmlSubstitutesPlaceholdersInLabelAndText(): void
    {
        $out = NotifyTemplateService::renderItemHtml('Welcome {name}, sign in at [{email}](https://mail.example.com)', [
            'name' => 'Jane', 'email' => 'jane@example.com',
        ]);
        self::assertStringContainsString('Welcome Jane,', $out);
        self::assertStringContainsString('>jane@example.com</a>', $out);
    }

    public function testDefaultsCoverBothDocs(): void
    {
        $d = NotifyTemplateService::defaults();
        self::assertArrayHasKey('new_teacher', $d);
        self::assertArrayHasKey('non_instructional', $d);
        foreach ($d as $doc) {
            self::assertNotSame('', $doc['heading']);
            self::assertNotSame('', $doc['body']);
            self::assertNotEmpty(NotifyTemplateService::parseBody($doc['body']));
        }
    }
}
