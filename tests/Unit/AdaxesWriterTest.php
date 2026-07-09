<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdaxesWriter;
use PHPUnit\Framework\TestCase;

/**
 * AdaxesWriter — the direct-AD provisioning write client. It must (a) stay OFF
 * until ADAXES_WRITE_ENABLED + a credential are set, (b) send the right method
 * and body for create / modify / disable / enable, (c) refuse to push the
 * immutable sAMAccountName on a modify, (d) surface the returned objectGUID on
 * create, and (e) degrade to a clear ok=false envelope — never throw — on 4xx /
 * 5xx / transport failure. The HTTP call is injected so no live server is needed.
 */
final class AdaxesWriterTest extends TestCase
{
    /** A configured, write-enabled writer with a static token and canned response. */
    private function writer(?array $response, ?array &$captured = null): AdaxesWriter
    {
        $fetch = function (string $method, string $url, array $headers, ?string $body) use ($response, &$captured): ?array {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
            return $response;
        };
        // (baseUrl, username, password, timeout, fetch, token, writeEnabled)
        return new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, $fetch, 'test-token', true);
    }

    public function testOffByDefault(): void
    {
        // Write-enabled flag false → not configured; every method no-ops with a reason.
        $w = new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, fn() => null, 'test-token', false);
        self::assertFalse($w->configured());

        $res = $w->create('OU=Staff,DC=x', ['sAMAccountName' => 'jsmith']);
        self::assertFalse($res['ok']);
        self::assertFalse($res['created']);
        self::assertStringContainsString('ADAXES_WRITE_ENABLED', (string) $res['error']);
    }

    public function testConfiguredRequiresBaseUrlAndCredential(): void
    {
        self::assertTrue($this->writer(null)->configured());
        // Enabled but no base URL / credential → still not configured.
        $w = new AdaxesWriter('', '', '', 5, fn() => null, '', true);
        self::assertFalse($w->configured());
    }

    public function testCreatePostsUserWithPropertiesAndReturnsGuid(): void
    {
        $captured = null;
        $w = $this->writer([
            'status' => 200,
            'body'   => json_encode(['objectGUID' => '2b6160e2-ad91-419c-8960-cf672c75528f', 'sAMAccountName' => 'jsmith']),
        ], $captured);

        $res = $w->create('OU=Faculty,DC=example,DC=org', [
            'sAMAccountName'    => 'jsmith',
            'userPrincipalName' => 'jsmith@tusc.k12.al.us',
            'mail'              => 'jsmith@tusc.k12.al.us',
            'displayName'       => 'John Smith',
        ]);

        self::assertTrue($res['ok']);
        self::assertTrue($res['created']);
        self::assertSame('2b6160e2-ad91-419c-8960-cf672c75528f', $res['guid']);

        self::assertSame('POST', $captured['method']);
        self::assertStringContainsString('/api/directoryObjects', $captured['url']);
        self::assertSame('test-token', $captured['headers']['Adm-Authorization']);

        $body = json_decode((string) $captured['body'], true);
        self::assertSame('user', $body['objectType']);
        self::assertSame('OU=Faculty,DC=example,DC=org', $body['path']);
        $props = [];
        foreach ($body['properties'] as $p) {
            $props[$p['name']] = $p['value'];
        }
        self::assertSame('jsmith', $props['sAMAccountName']);
        self::assertSame('jsmith@tusc.k12.al.us', $props['mail']);
    }

    public function testCreateWithNoGuidInResponseIsCreatedButUnresolved(): void
    {
        // A bare 204-style success: created, but the GUID must be re-resolved by
        // the caller (search), so the writer returns guid=null, created=true.
        $w = $this->writer(['status' => 204, 'body' => '']);
        $res = $w->create('OU=Staff,DC=x', ['sAMAccountName' => 'jsmith']);
        self::assertTrue($res['ok']);
        self::assertTrue($res['created']);
        self::assertNull($res['guid']);
    }

    public function testModifyPatchesChangedAttributesByGuid(): void
    {
        $captured = null;
        $w = $this->writer(['status' => 200, 'body' => '{}'], $captured);

        $res = $w->modify('2b6160e2-ad91-419c-8960-cf672c75528f', [
            'mail'              => 'new@tusc.k12.al.us',
            'userPrincipalName' => 'new@tusc.k12.al.us',
        ]);

        self::assertTrue($res['ok']);
        self::assertSame('PATCH', $captured['method']);
        self::assertStringContainsString('directoryObject=2b6160e2-ad91-419c-8960-cf672c75528f', $captured['url']);
        self::assertArrayHasKey('mail', $res['changed']);
        self::assertArrayHasKey('userPrincipalName', $res['changed']);
    }

    public function testModifyNeverSendsImmutableSamAccountName(): void
    {
        $captured = null;
        $w = $this->writer(['status' => 200, 'body' => '{}'], $captured);

        $res = $w->modify('2b6160e2-ad91-419c-8960-cf672c75528f', [
            'sAMAccountName' => 'renamed',      // must be dropped
            'mail'           => 'x@tusc.k12.al.us',
        ]);

        self::assertTrue($res['ok']);
        self::assertArrayNotHasKey('sAMAccountName', $res['changed']);
        $body = json_decode((string) $captured['body'], true);
        $names = array_column($body['properties'], 'name');
        self::assertNotContains('sAMAccountName', $names);
        self::assertContains('mail', $names);
    }

    public function testModifyWithOnlyImmutableIsANoOpWithNoRequest(): void
    {
        $captured = null;
        $w = $this->writer(['status' => 200, 'body' => '{}'], $captured);
        $res = $w->modify('2b6160e2-ad91-419c-8960-cf672c75528f', ['sAMAccountName' => 'renamed']);
        self::assertTrue($res['ok']);
        self::assertSame([], $res['changed']);
        self::assertNull($captured); // never hit the wire
    }

    public function testDisableTogglesAccountDisabledViaModify(): void
    {
        $captured = null;
        $w = $this->writer(['status' => 200, 'body' => '{}'], $captured);

        $res = $w->disable('2b6160e2-ad91-419c-8960-cf672c75528f');
        self::assertTrue($res['ok']);
        self::assertTrue($res['changed']);
        self::assertSame('PATCH', $captured['method']);
        $body = json_decode((string) $captured['body'], true);
        $props = [];
        foreach ($body['properties'] as $p) {
            $props[$p['name']] = $p['value'];
        }
        self::assertSame('true', $props['accountDisabled']);
    }

    public function testEnableSetsAccountDisabledFalse(): void
    {
        $captured = null;
        $w = $this->writer(['status' => 200, 'body' => '{}'], $captured);

        $res = $w->enable('2b6160e2-ad91-419c-8960-cf672c75528f');
        self::assertTrue($res['ok']);
        $body = json_decode((string) $captured['body'], true);
        $props = [];
        foreach ($body['properties'] as $p) {
            $props[$p['name']] = $p['value'];
        }
        self::assertSame('false', $props['accountDisabled']);
    }

    public function testTransportFailureReturnsUnreachable(): void
    {
        $w = $this->writer(null); // transport failure
        $res = $w->create('OU=Staff,DC=x', ['sAMAccountName' => 'jsmith']);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('unreachable', (string) $res['error']);
    }

    public function testClientErrorSurfacesHttpStatus(): void
    {
        $w = $this->writer(['status' => 400, 'body' => 'bad request']);
        $res = $w->modify('2b6160e2-ad91-419c-8960-cf672c75528f', ['mail' => 'x@tusc.k12.al.us']);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('HTTP 400', (string) $res['error']);
    }

    public function testServerErrorSurfacesHttpStatus(): void
    {
        $w = $this->writer(['status' => 500, 'body' => 'boom']);
        $res = $w->create('OU=Staff,DC=x', ['sAMAccountName' => 'jsmith']);
        self::assertFalse($res['ok']);
        self::assertStringContainsString('HTTP 500', (string) $res['error']);
    }

    public function testRejectedCredentialsReported(): void
    {
        $w = $this->writer(['status' => 403, 'body' => '']);
        $res = $w->disable('2b6160e2-ad91-419c-8960-cf672c75528f');
        self::assertFalse($res['ok']);
        self::assertStringContainsString('credentials', (string) $res['error']);
    }

    public function testDedicatedDisablePathIsPostedWhenConfigured(): void
    {
        putenv('ADAXES_DISABLE_PATH=api/directoryObjects/disable');
        try {
            $captured = null;
            $fetch = function (string $method, string $url, array $headers, ?string $body) use (&$captured): ?array {
                $captured = ['method' => $method, 'url' => $url, 'body' => $body];
                return ['status' => 200, 'body' => '{}'];
            };
            $w = new AdaxesWriter('https://adx.example.org/restv2', '', '', 5, $fetch, 'test-token', true);
            $res = $w->disable('2b6160e2-ad91-419c-8960-cf672c75528f');
            self::assertTrue($res['ok']);
            self::assertSame('POST', $captured['method']);
            self::assertStringContainsString('/api/directoryObjects/disable', $captured['url']);
        } finally {
            putenv('ADAXES_DISABLE_PATH');
        }
    }
}
