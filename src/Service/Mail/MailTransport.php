<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * A pluggable email transport. The Mailer composes a message and hands it to a
 * transport; the transport is the only piece that talks to the outside world, so
 * it is injectable and the rest of the mail path unit-tests with a fake.
 *
 * @phpstan-type Message array{from:string, to:list<string>, cc:list<string>, subject:string, body:string}
 */
interface MailTransport
{
    /**
     * Deliver a message. Never throws — returns an envelope.
     *
     * @param Message $message
     * @return array{ok:bool, error:?string}
     */
    public function send(array $message): array;

    /** Whether this transport is configured to actually deliver mail. */
    public function configured(): bool;

    /** Short label for logs/UI (e.g. "smtp", "sendmail", "disabled"). */
    public function name(): string;
}
