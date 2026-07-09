<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The default transport when mail isn't configured: it delivers nothing and says
 * so. The Mailer still logs the message to email_outbox (status 'queued'), so a
 * send is never silently lost — an operator can see what would have gone out and
 * wire up a real transport later.
 */
final class NullMailTransport implements MailTransport
{
    public function send(array $message): array
    {
        return ['ok' => false, 'error' => 'Mail transport is not configured (set MAIL_TRANSPORT).'];
    }

    public function configured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'disabled';
    }
}
