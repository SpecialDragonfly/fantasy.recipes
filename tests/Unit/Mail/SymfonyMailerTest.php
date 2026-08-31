<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\SymfonyMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * SymfonyMailer just maps the app's 3-arg Mailer::send() onto a
 * symfony/mailer Email. These tests capture the Email handed to the
 * underlying MailerInterface rather than sending anything.
 */
final class SymfonyMailerTest extends TestCase
{
    public function testSendBuildsATextEmailWithTheConfiguredFrom(): void
    {
        $capture = new class implements MailerInterface {
            public ?RawMessage $message = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->message = $message;
            }
        };

        $mailer = new SymfonyMailer($capture, new Address('noreply@fantasyrecipes.co.uk', 'Fantasy Recipes'));

        $mailer->send('user@example.com', 'Reset your password', "Follow this link:\nhttps://example.test/x");

        self::assertInstanceOf(Email::class, $capture->message);
        /** @var Email $email */
        $email = $capture->message;

        self::assertSame('Reset your password', $email->getSubject());
        self::assertSame("Follow this link:\nhttps://example.test/x", $email->getTextBody());
        self::assertSame('user@example.com', $email->getTo()[0]->getAddress());

        $from = $email->getFrom()[0];
        self::assertSame('noreply@fantasyrecipes.co.uk', $from->getAddress());
        self::assertSame('Fantasy Recipes', $from->getName());
    }

    public function testFromDsnBuildsAWorkingInstanceForTheResendScheme(): void
    {
        // resend+api:// is registered by symfony/resend-mailer; this just
        // checks the DSN parses and wires up without touching the network.
        $mailer = SymfonyMailer::fromDsn(
            'resend+api://re_test_key@default',
            'noreply@fantasyrecipes.co.uk',
            'Fantasy Recipes',
        );

        self::assertInstanceOf(SymfonyMailer::class, $mailer);
    }
}
