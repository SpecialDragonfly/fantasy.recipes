<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RecipeEmailQueueRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The scheduling / listing bits of the queue repo that the
 * RecipeNotifications end-to-end test doesn't reach (its send path only
 * ever runs "due" campaigns).
 */
final class RecipeEmailQueueRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RecipeEmailQueueRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repo = new RecipeEmailQueueRepository($this->pdo);
    }

    /**
     * @param list<int> $recipeIds
     */
    private function campaign(string $scheduledFor, array $recipeIds = [], int $recipients = 1): int
    {
        $r = [];
        for ($i = 1; $i <= $recipients; $i++) {
            $uid = InMemoryDatabase::seedUser($this->pdo, 'u' . uniqid());
            $r[] = ['user_id' => $uid, 'email' => "u$uid@example.com"];
        }

        return $this->repo->createCampaign('single', 'Subject', $scheduledFor, $recipeIds, $r);
    }

    public function testDueCampaignIdsRespectsStatusAndSchedule(): void
    {
        $past = $this->campaign('2020-01-01 00:00:00');
        $future = $this->campaign('2999-01-01 00:00:00');
        $sent = $this->campaign('2020-01-01 00:00:00');
        $this->repo->markCampaignSent($sent);

        self::assertSame([$past], $this->repo->dueCampaignIds('2026-01-01 00:00:00'));
    }

    public function testRescheduleMovesACampaignInOrOutOfTheDueWindow(): void
    {
        $id = $this->campaign('2999-01-01 00:00:00');
        self::assertSame([], $this->repo->dueCampaignIds('2026-01-01 00:00:00'));

        $this->repo->rescheduleCampaign($id, '2025-06-01 00:00:00');
        self::assertSame([$id], $this->repo->dueCampaignIds('2026-01-01 00:00:00'));
    }

    public function testRecipeTitlesForGroupsByCampaign(): void
    {
        $r1 = InMemoryDatabase::seedRecipe($this->pdo, 'first');
        $r2 = InMemoryDatabase::seedRecipe($this->pdo, 'second');
        $a = $this->campaign('2026-01-01 00:00:00', [$r1, $r2]);
        $b = $this->campaign('2026-01-01 00:00:00', [$r1]);

        $titles = $this->repo->recipeTitlesFor([$a, $b]);

        self::assertCount(2, $titles[$a]);
        self::assertCount(1, $titles[$b]);
        self::assertSame([], $this->repo->recipeTitlesFor([]));
    }

    public function testCreateCampaignSnapshotsRecipientsAsPendingDeliveries(): void
    {
        $id = $this->campaign('2026-01-01 00:00:00', [], 3);

        self::assertCount(3, $this->repo->pendingDeliveries($id));
        $campaign = $this->repo->findCampaign($id);
        self::assertNotNull($campaign);
        self::assertSame(3, (int) $campaign['recipients_total']);
        self::assertSame(['pending' => 3, 'sent' => 0, 'failed' => 0], $this->repo->deliveryStatusCounts($id));
    }

    public function testListCampaignsIsNewestFirst(): void
    {
        $a = $this->campaign('2026-01-01 00:00:00');
        $b = $this->campaign('2026-01-01 00:00:00');

        $ids = array_map(static fn (array $c): int => (int) $c['id'], $this->repo->listCampaigns());

        self::assertSame([$b, $a], $ids);
    }
}
