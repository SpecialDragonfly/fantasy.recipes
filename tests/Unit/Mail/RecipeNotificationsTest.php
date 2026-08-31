<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\Mailer;
use App\Mail\RecipeNotifications;
use App\Repository\RecipeEmailQueueRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\Views\Twig;

/**
 * End-to-end over the real repositories + the real email templates, with a
 * recording/failing Mailer double. Covers enqueue (single vs digest vs
 * nothing), a clean send, a mid-send failure + resume, and cancel.
 */
final class RecipeNotificationsTest extends TestCase
{
    private PDO $pdo;
    private RecipeRepository $recipes;
    private UserRepository $users;
    private RecipeEmailQueueRepository $queue;
    private RecordingMailer $mailer;
    private RecipeNotifications $notifications;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->recipes = new RecipeRepository($this->pdo);
        $this->users = new UserRepository($this->pdo);
        $this->queue = new RecipeEmailQueueRepository($this->pdo);
        $this->mailer = new RecordingMailer();

        $twig = Twig::create(dirname(__DIR__, 3) . '/templates', ['cache' => false]);

        $this->notifications = new RecipeNotifications(
            $this->recipes,
            $this->users,
            $this->queue,
            $this->mailer,
            $twig,
            'https://fantasyrecipes.co.uk',
        );
    }


    /**
     * @return array<string, mixed>
     */
    private function campaignRow(int $id): array
    {
        $row = $this->queue->findCampaign($id);
        self::assertNotNull($row);

        return $row;
    }

    private function publishedRecipe(string $slug): int
    {
        return InMemoryDatabase::seedRecipe($this->pdo, $slug, true);
    }

    private function optedInUser(string $name): int
    {
        return $this->users->create($name, $name . '@example.com', 'password123', true);
    }

    public function testEnqueueDoesNothingWhenNoUnannouncedRecipes(): void
    {
        $this->optedInUser('sub');

        self::assertNull($this->notifications->enqueuePending());
        self::assertSame([], $this->queue->listCampaigns());
    }

    public function testOneRecipeMakesASingleCampaign(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('sub');

        $id = $this->notifications->enqueuePending();
        self::assertNotNull($id);

        $campaign = $this->queue->findCampaign($id);
        self::assertNotNull($campaign);
        self::assertSame('single', $campaign['kind']);
        self::assertStringStartsWith('New recipe:', (string) $campaign['subject']);
        self::assertSame(1, (int) $campaign['recipients_total']);

        // recipe is now marked announced
        self::assertSame([], $this->recipes->listPublishedAwaitingAnnouncement());
    }

    public function testTwoRecipesMakeADigestCampaign(): void
    {
        $this->publishedRecipe('r1');
        $this->publishedRecipe('r2');
        $this->optedInUser('a');
        $this->optedInUser('b');

        $id = $this->notifications->enqueuePending();
        $campaign = $this->queue->findCampaign((int) $id);

        self::assertNotNull($campaign);
        self::assertSame('digest', $campaign['kind']);
        self::assertSame('2 new recipes on Fantasy Recipes', $campaign['subject']);
        self::assertSame(2, (int) $campaign['recipients_total']);
    }

    public function testRecipesAreMarkedAnnouncedEvenWithNoSubscribers(): void
    {
        $this->publishedRecipe('r1');

        self::assertNull($this->notifications->enqueuePending());
        self::assertSame([], $this->recipes->listPublishedAwaitingAnnouncement());
        self::assertSame([], $this->queue->listCampaigns());
    }

    public function testSendCampaignDeliversToEveryoneAndMarksItSent(): void
    {
        $this->publishedRecipe('gnocchi');
        $this->optedInUser('alice');
        $this->optedInUser('bob');
        $id = (int) $this->notifications->enqueuePending();

        $result = $this->notifications->sendCampaign($id);

        self::assertSame(['sent' => 2, 'failed' => 0, 'stopped' => false], $result);
        self::assertCount(2, $this->mailer->sent);
        self::assertSame('sent', $this->campaignRow($id)['status']);

        $body = $this->mailer->sent[0]['body'];
        self::assertStringContainsString('https://fantasyrecipes.co.uk/recipes/gnocchi', $body);
        self::assertStringContainsString('https://fantasyrecipes.co.uk/unsubscribe?u=', $body);

        $headers = $this->mailer->sent[0]['headers'];
        self::assertStringContainsString('/unsubscribe?u=', $headers['List-Unsubscribe']);
        self::assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function testAMidSendFailurePausesTheCampaignAndRetryResumes(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('alice');
        $bob = $this->optedInUser('bob');
        $this->optedInUser('carol');
        $id = (int) $this->notifications->enqueuePending();

        // Fail on bob only.
        $this->mailer->failFor = 'bob@example.com';

        $result = $this->notifications->sendCampaign($id);
        self::assertTrue($result['stopped']);
        self::assertSame(1, $result['sent']);
        self::assertSame(1, $result['failed']);

        $campaign = $this->campaignRow($id);
        self::assertSame('failed', $campaign['status']);
        self::assertNotNull($campaign['last_error']);
        self::assertSame(1, $this->queue->deliveryStatusCounts($id)['pending']); // carol untouched

        // Provider recovers; retry sends the failed one + the pending one.
        $this->mailer->failFor = null;
        $retry = $this->notifications->retryCampaign($id);

        self::assertFalse($retry['stopped']);
        self::assertSame('sent', $this->campaignRow($id)['status']);
        self::assertSame(3, $this->queue->deliveryStatusCounts($id)['sent']);
    }

    public function testCancelReleasesTheRecipesForReannouncement(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('alice');
        $id = (int) $this->notifications->enqueuePending();

        self::assertTrue($this->notifications->cancelCampaign($id));
        self::assertSame('cancelled', $this->campaignRow($id)['status']);
        self::assertCount(1, $this->recipes->listPublishedAwaitingAnnouncement());
    }

    public function testSendDeliveryTargetsOneRecipientAndLeavesTheRest(): void
    {
        $this->publishedRecipe('gnocchi');
        $this->optedInUser('alice');
        $this->optedInUser('bob');
        $id = (int) $this->notifications->enqueuePending();

        $first = $this->queue->pendingDeliveries($id)[0];
        $result = $this->notifications->sendDelivery($first['id']);

        self::assertSame(['sent' => 1, 'failed' => 0, 'missing' => false], $result);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame($first['recipient_email'], $this->mailer->sent[0]['to']);

        $counts = $this->queue->deliveryStatusCounts($id);
        self::assertSame(1, $counts['sent']);
        self::assertSame(1, $counts['pending']);
        // one recipient still outstanding, so the campaign stays open
        self::assertSame('pending', $this->campaignRow($id)['status']);
    }

    public function testSendDeliveryClosesTheCampaignWhenItWasTheLastOutstanding(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('alice');
        $id = (int) $this->notifications->enqueuePending();

        $only = $this->queue->pendingDeliveries($id)[0];
        $this->notifications->sendDelivery($only['id']);

        self::assertSame('sent', $this->campaignRow($id)['status']);
    }

    public function testSendDeliveryRecordsAFailureWithoutPausingTheCampaign(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('alice');
        $this->optedInUser('bob');
        $id = (int) $this->notifications->enqueuePending();

        $this->mailer->failFor = 'alice@example.com';
        $alice = null;
        foreach ($this->queue->pendingDeliveries($id) as $d) {
            if ($d['recipient_email'] === 'alice@example.com') {
                $alice = $d;
            }
        }
        self::assertNotNull($alice);

        $result = $this->notifications->sendDelivery($alice['id']);

        self::assertSame(['sent' => 0, 'failed' => 1, 'missing' => false], $result);
        self::assertSame(1, $this->queue->deliveryStatusCounts($id)['failed']);
        self::assertSame('pending', $this->campaignRow($id)['status']);
    }

    public function testSendDeliveryIsANoOpForAnAlreadySentRow(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('alice');
        $id = (int) $this->notifications->enqueuePending();
        $only = $this->queue->pendingDeliveries($id)[0];

        $this->notifications->sendDelivery($only['id']);
        $again = $this->notifications->sendDelivery($only['id']);

        self::assertTrue($again['missing']);
        self::assertCount(1, $this->mailer->sent);
    }

    public function testSendDeliveryRefusesACancelledCampaign(): void
    {
        $this->publishedRecipe('r1');
        $this->optedInUser('alice');
        $id = (int) $this->notifications->enqueuePending();
        $only = $this->queue->pendingDeliveries($id)[0];

        $this->notifications->cancelCampaign($id);
        $result = $this->notifications->sendDelivery($only['id']);

        self::assertTrue($result['missing']);
        self::assertCount(0, $this->mailer->sent);
    }
}

/**
 * @internal
 */
final class RecordingMailer implements Mailer
{
    /** @var list<array{to: string, subject: string, body: string, headers: array<string, string>}> */
    public array $sent = [];

    public ?string $failFor = null;

    public function send(string $toAddress, string $subject, string $textBody, array $headers = []): void
    {
        if ($this->failFor !== null && $toAddress === $this->failFor) {
            throw new RuntimeException('provider rate limit');
        }

        $this->sent[] = ['to' => $toAddress, 'subject' => $subject, 'body' => $textBody, 'headers' => $headers];
    }
}
