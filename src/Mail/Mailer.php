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
    public function send(string $toAddress, string $subject, string $textBody): void;
}
