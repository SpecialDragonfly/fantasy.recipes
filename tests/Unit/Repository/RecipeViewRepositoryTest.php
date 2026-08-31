<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RecipeViewRepository;
use App\Tests\Support\InMemoryDatabase;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecipeViewRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RecipeViewRepository $repo;
    private int $stew;
    private int $pie;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repo = new RecipeViewRepository($this->pdo);
        $this->stew = InMemoryDatabase::seedRecipe($this->pdo, 'stew');
        $this->pie = InMemoryDatabase::seedRecipe($this->pdo, 'pie');
    }

    private function view(int $recipeId, string $at): void
    {
        $this->pdo->prepare('INSERT INTO recipe_views (recipe_id, viewed_at) VALUES (?, ?)')
            ->execute([$recipeId, $at]);
    }

    public function testRecordInsertsARowStampedNow(): void
    {
        $this->repo->record($this->stew);

        $result = $this->pdo->query('SELECT recipe_id, viewed_at FROM recipe_views');
        self::assertNotFalse($result);
        $rows = $result->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame($this->stew, (int) $rows[0]['recipe_id']);
        self::assertStringStartsWith(
            (new DateTimeImmutable())->format('Y-m-d'),
            (string) $rows[0]['viewed_at'],
        );
    }

    public function testDailyTotalsGroupsAllRecipesByDayNewestFirst(): void
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('-1 day'))->format('Y-m-d');

        $this->view($this->stew, $today . ' 08:00:00');
        $this->view($this->pie, $today . ' 09:00:00');
        $this->view($this->stew, $today . ' 10:00:00');
        $this->view($this->pie, $yesterday . ' 20:00:00');

        $totals = $this->repo->dailyTotals(90);

        self::assertSame(
            [['day' => $today, 'views' => 3], ['day' => $yesterday, 'views' => 1]],
            $totals,
        );
    }

    public function testDailyTotalsRespectsTheWindow(): void
    {
        $this->view($this->stew, (new DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->view($this->pie, (new DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'));

        self::assertCount(1, $this->repo->dailyTotals(7));
    }

    public function testTopRecipesRanksByRecentViewsWithWindowedCounts(): void
    {
        $now = new DateTimeImmutable();
        $in7 = $now->modify('-2 days')->format('Y-m-d 12:00:00');
        $in30 = $now->modify('-20 days')->format('Y-m-d 12:00:00');
        $old = $now->modify('-100 days')->format('Y-m-d 12:00:00');

        // stew: 1 in last 7d, 2 in last 30d, 3 all time
        $this->view($this->stew, $in7);
        $this->view($this->stew, $in30);
        $this->view($this->stew, $old);
        // pie: 0 in 7d, 1 in 30d, 1 all time
        $this->view($this->pie, $in30);

        $top = $this->repo->topRecipes(50);

        self::assertCount(2, $top);
        self::assertSame('stew', $top[0]['slug']);
        self::assertSame(1, $top[0]['views_7d']);
        self::assertSame(2, $top[0]['views_30d']);
        self::assertSame(3, $top[0]['views_all']);
        self::assertSame('pie', $top[1]['slug']);
        self::assertSame(0, $top[1]['views_7d']);
        self::assertSame(1, $top[1]['views_30d']);
        self::assertSame(1, $top[1]['views_all']);
    }

    public function testTopRecipesOmitsRecipesWithNoViewsAndHonoursLimit(): void
    {
        $this->view($this->stew, (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        $top = $this->repo->topRecipes(1);

        self::assertCount(1, $top);
        self::assertSame('stew', $top[0]['slug']);
    }

    public function testDeletingARecipeCascadesItsViews(): void
    {
        $this->view($this->stew, (new DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->pdo->exec('DELETE FROM recipes WHERE id = ' . $this->stew);

        $result = $this->pdo->query('SELECT COUNT(*) FROM recipe_views');
        self::assertNotFalse($result);
        self::assertSame(0, (int) $result->fetchColumn());
    }
}
