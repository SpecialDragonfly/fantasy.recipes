<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mailer\Mailer as SymfonyComponentMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Real `Mailer`, backed by symfony/mailer. Used in place of LogMailer once
 * MAIL_API_KEY is set (see src/bootstrap.php). The concrete transport is
 * whatever the DSN names -- currently Resend (`resend+api://KEY@default`,
 * via symfony/resend-mailer), but the interface and callers don't care.
 *
 * Password-reset mail is plain text only (see App\Mail\Mailer / the
 * password_reset route), so this only ever calls Email::text().
 */
final class SymfonyMailer implements Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Address $from,
    ) {
    }

    /**
     * Build one from a mailer DSN and a From identity. Transport::fromDsn()
     * discovers the transport factory from the installed bridges (the
     * `resend+api` scheme needs symfony/resend-mailer), and the API
     * transports need symfony/http-client.
     */
    public static function fromDsn(string $dsn, string $fromAddress, string $fromName): self
    {
        return new self(
            new SymfonyComponentMailer(Transport::fromDsn($dsn)),
            new Address($fromAddress, $fromName),
        );
    }

    public function send(string $toAddress, string $subject, string $textBody, array $headers = []): void
    {
        $email = (new Email())
            ->from($this->from)
            ->to($toAddress)
            ->subject($subject)
            ->text($textBody);

        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }

        $this->mailer->send($email);
    }
}
