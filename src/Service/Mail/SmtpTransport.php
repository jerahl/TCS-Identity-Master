<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * A minimal SMTP client (no external dependency): EHLO, optional STARTTLS,
 * optional AUTH LOGIN, then MAIL FROM / RCPT TO / DATA. Suitable for an internal
 * relay or an authenticated submission host (e.g. Exchange Online on :587 with
 * STARTTLS). Never throws — every failure becomes an ok=false envelope.
 *
 * Confirm host/port/security against your relay before enabling; the endpoints
 * are site-specific just like the Adaxes ones.
 */
final class SmtpTransport implements MailTransport
{
    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $security = 'tls',   // none | tls (STARTTLS) | ssl (implicit)
        private readonly int $timeout = 15,
        private readonly string $ehloName = 'localhost',
    ) {
    }

    public function configured(): bool
    {
        return trim($this->host) !== '';
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(array $message): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'error' => 'SMTP host not set (SMTP_HOST).'];
        }
        $recipients = array_values(array_filter(array_merge($message['to'], $message['cc'] ?? [])));
        if ($recipients === []) {
            return ['ok' => false, 'error' => 'No recipients.'];
        }

        $scheme = $this->security === 'ssl' ? 'ssl://' : '';
        $fp = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if ($fp === false) {
            return ['ok' => false, 'error' => "SMTP connect failed: {$errstr} ({$errno})."];
        }
        stream_set_timeout($fp, $this->timeout);

        try {
            $this->expect($fp, 220);
            $this->cmd($fp, 'EHLO ' . $this->ehloName, 250);

            if ($this->security === 'tls') {
                $this->cmd($fp, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                    return ['ok' => false, 'error' => 'STARTTLS negotiation failed.'];
                }
                $this->cmd($fp, 'EHLO ' . $this->ehloName, 250); // re-EHLO after TLS
            }

            if ($this->username !== '') {
                $this->cmd($fp, 'AUTH LOGIN', 334);
                $this->cmd($fp, base64_encode($this->username), 334);
                $this->cmd($fp, base64_encode($this->password), 235);
            }

            $this->cmd($fp, 'MAIL FROM:<' . self::addr($message['from']) . '>', 250);
            foreach ($recipients as $rcpt) {
                $this->cmd($fp, 'RCPT TO:<' . self::addr($rcpt) . '>', 250);
            }
            $this->cmd($fp, 'DATA', 354);
            $this->write($fp, $this->buildData($message) . "\r\n.");
            $this->expect($fp, 250);
            $this->write($fp, 'QUIT');

            return ['ok' => true, 'error' => null];
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @fclose($fp);
        }
    }

    /** @param resource $fp */
    private function cmd($fp, string $line, int $expect): void
    {
        $this->write($fp, $line);
        $this->expect($fp, $expect);
    }

    /** @param resource $fp */
    private function write($fp, string $line): void
    {
        if (@fwrite($fp, $line . "\r\n") === false) {
            throw new \RuntimeException('SMTP write failed.');
        }
    }

    /** @param resource $fp Read the (possibly multi-line) reply and assert its code. */
    private function expect($fp, int $code): void
    {
        $line = (string) fgets($fp, 515);
        // Multi-line replies use "code-..." for continuation and "code ..." on the last line.
        while ($line !== '' && strlen($line) >= 4 && $line[3] === '-') {
            $line = (string) fgets($fp, 515);
        }
        $got = (int) substr($line, 0, 3);
        if ($got !== $code) {
            throw new \RuntimeException("SMTP: expected {$code}, got " . trim($line));
        }
    }

    /** @param array{from:string,to:list<string>,cc:list<string>,subject:string,body:string} $m */
    private function buildData(array $m): string
    {
        $date = gmdate('D, d M Y H:i:s') . ' +0000';
        $lines = [
            'Date: ' . $date,
            'From: ' . $m['from'],
            'To: ' . implode(', ', $m['to']),
        ];
        if (($m['cc'] ?? []) !== []) {
            $lines[] = 'Cc: ' . implode(', ', $m['cc']);
        }
        $lines[] = 'Subject: ' . self::encodeHeader($m['subject']);
        $lines[] = 'MIME-Version: 1.0';
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: 8bit';
        $lines[] = '';
        // Dot-stuff any line beginning with a period (SMTP transparency).
        $body = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $m['body']));
        $lines[] = str_replace("\n", "\r\n", (string) $body);
        return implode("\r\n", $lines);
    }

    /** Encode a non-ASCII subject as RFC 2047. */
    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    /** Extract a bare address from a "Name <addr>" string. */
    private static function addr(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            return trim($m[1]);
        }
        return trim($value);
    }
}
