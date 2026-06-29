<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\SamlLog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SamlLog is the reliable channel for SAML failure reasons (php-fpm worker
 * error_log is often invisible in the journal). It must be a no-op when
 * disabled, write JSON-per-line when enabled, and never throw.
 */
final class SamlLogTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        $this->logPath = sys_get_temp_dir() . '/idm_saml_test_' . getmypid() . '.log';
        @unlink($this->logPath);
        putenv('SAML_LOG=' . $this->logPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);
        putenv('SAML_DEBUG');
        putenv('SAML_LOG');
    }

    public function testNoOpWhenDisabled(): void
    {
        putenv('SAML_DEBUG=false');
        SamlLog::failure('acs', new RuntimeException('boom'), null);
        self::assertFileDoesNotExist($this->logPath);
    }

    public function testWritesReasonWhenEnabled(): void
    {
        putenv('SAML_DEBUG=true');
        self::assertSame($this->logPath, SamlLog::path());

        SamlLog::failure('acs', new RuntimeException('destination mismatch'), null);

        self::assertFileExists($this->logPath);
        $line = trim((string) file_get_contents($this->logPath));
        $row = json_decode($line, true);
        self::assertSame('acs', $row['phase']);
        self::assertSame('destination mismatch', $row['reason']);
        self::assertArrayHasKey('ts', $row);
    }

    public function testRecordsDecodedSamlResponseWhenPresent(): void
    {
        putenv('SAML_DEBUG=true');
        $xml = '<samlp:Response>unsigned-assertion</samlp:Response>';
        SamlLog::failure('acs', new RuntimeException('not signed'), base64_encode($xml));

        $row = json_decode(trim((string) file_get_contents($this->logPath)), true);
        self::assertSame($xml, $row['saml_response']);
    }
}
