<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Delivery via PHP's mail() (the host MTA / sendmail binary). Zero external
 * config — works when the host has a working sendmail/postfix. Good for a box
 * that already relays mail; for a remote relay with auth use SmtpTransport.
 */
final class SendmailTransport implements MailTransport
{
    public function send(array $message): array
    {
        $to = implode(', ', $message['to']);
        if (trim($to) === '') {
            return ['ok' => false, 'error' => 'No recipients.'];
        }

        $headers = ['From: ' . $message['from'], 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
        if (($message['cc'] ?? []) !== []) {
            $headers[] = 'Cc: ' . implode(', ', $message['cc']);
        }

        $ok = @mail($to, $message['subject'], $message['body'], implode("\r\n", $headers));
        return $ok ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'mail() returned false (host MTA rejected or unavailable).'];
    }

    public function configured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'sendmail';
    }
}
