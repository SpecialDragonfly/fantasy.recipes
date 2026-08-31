<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The one place the app sends email (see spec.md -- Immersion Rules: no
 * submission-status notifications, password reset only). Kept behind an
 * interface so the concrete transport can change without touching callers --
 * see architecture.md -- Open Questions for the still-undecided transactional
 * provider.
 */
interface Mailer
{
    /**
     * @param array<string, string> $headers extra headers, e.g.
     *     List-Unsubscribe on the marketing emails (RFC 8058). Ignored by
     *     transports that can't carry them.
     */
    public function send(string $toAddress, string $subject, string $textBody, array $headers = []): void;
}
