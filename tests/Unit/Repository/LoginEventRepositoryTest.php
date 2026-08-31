<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\LoginEventRepository;
use App\Tests\Support\InMemoryDatabase;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class LoginEventRepositoryTest extends TestCase
{
    private PDO $pdo;
    private LoginEventRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repo = new LoginEventRepository($this->pdo);

        InMemoryDatabase::seedUser($this->pdo, 'alice');
        InMemoryDatabase::seedUser($this->pdo, 'bob');
    }

    private function event(int $userId, string $at): void
    {
        $this->pdo->prepare('INSERT INTO login_events (user_id, logged_in_at) VALUES (?, ?)')
            ->execute([$userId, $at]);
    }

    public function testRecordInsertsARowStampedNow(): void
    {
        $this->repo->record(1);

        $result = $this->pdo->query('SELECT user_id, logged_in_at FROM login_events');
        self::assertNotFalse($result);
        $rows = $result->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['user_id']);
        self::assertStringStartsWith(
            (new DateTimeImmutable())->format('Y-m-d'),
            (string) $rows[0]['logged_in_at'],
        );
    }

    public function testDailyCountsGroupsByDayWithDistinctAndTotal(): void
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');

        // today: alice twice, bob once -> 2 users / 3 logins
        $this->event(1, $today . ' 08:00:00');
        $this->event(1, $today . ' 19:30:00');
        $this->event(2, $today . ' 12:00:00');
        // yesterday: alice once -> 1 user / 1 login
        $this->event(1, $yesterday . ' 09:00:00');

        $counts = $this->repo->dailyCounts(90);

        self::assertCount(2, $counts);
        // newest day first
        self::assertSame($today, $counts[0]['day']);
        self::assertSame(2, $counts[0]['distinct_users']);
        self::assertSame(3, $counts[0]['total_logins']);
        self::assertSame($yesterday, $counts[1]['day']);
        self::assertSame(1, $counts[1]['distinct_users']);
        self::assertSame(1, $counts[1]['total_logins']);
    }

    public function testDailyCountsExcludesEventsOlderThanTheWindow(): void
    {
        $this->event(1, (new DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->event(2, (new DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'));

        $counts = $this->repo->dailyCounts(7);

        self::assertCount(1, $counts);
        self::assertSame((new DateTimeImmutable())->format('Y-m-d'), $counts[0]['day']);
    }

    public function testDailyCountsIsEmptyWithNoEvents(): void
    {
        self::assertSame([], $this->repo->dailyCounts(90));
    }
}
