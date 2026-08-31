<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Stand-in `Mailer` used until a transactional email provider is chosen
 * (see architecture.md -- Open Questions). Writes each message to
 * storage/mail/ instead of sending it, so the password-reset flow is fully
 * buildable/testable now -- swap in a real Mailer implementation (e.g. one
 * built on symfony/mailer against a provider DSN) later without touching any
 * caller of the Mailer interface.
 */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $logDir)
    {
    }

    public function send(string $toAddress, string $subject, string $textBody, array $headers = []): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0775, true);
        }

        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= sprintf("%s: %s\n", $name, $value);
        }

        $line = sprintf(
            "[%s] To: %s | Subject: %s\n%s%s\n\n",
            date('Y-m-d H:i:s'),
            $toAddress,
            $subject,
            $headerLines,
            $textBody,
        );

        file_put_contents($this->logDir . '/mail.log', $line, FILE_APPEND);
    }
}
