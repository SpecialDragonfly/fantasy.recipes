<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * The recipe-view event log -- one row per human view of a published
 * recipe (see db/migrations/20260831190000_create_recipe_views_table.php).
 * Written from the public recipe route; read by the admin metrics page.
 * In-house only, never sent anywhere.
 */
final class RecipeViewRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record one view. Called from src/Routes/public.php (bots and admins
     * are filtered out before this point).
     */
    public function record(int $recipeId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recipe_views (recipe_id, viewed_at) VALUES (:recipe_id, :at)',
        );
        $statement->execute([
            'recipe_id' => $recipeId,
            'at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Site-wide recipe views per day for the last $days days (today
     * inclusive), most recent first. Days with no views are absent.
     *
     * @return list<array{day: string, views: int}>
     */
    public function dailyTotals(int $days = 90): array
    {
        $since = (new DateTimeImmutable(sprintf('-%d days', max(0, $days - 1))))->format('Y-m-d 00:00:00');

        $statement = $this->pdo->prepare(
            'SELECT DATE(viewed_at) AS day, COUNT(*) AS views '
            . 'FROM recipe_views WHERE viewed_at >= :since '
            . 'GROUP BY DATE(viewed_at) ORDER BY day DESC',
        );
        $statement->execute(['since' => $since]);

        $rows = [];
        /** @var array{day: string, views: int|string} $row */
        foreach ($statement->fetchAll() as $row) {
            $rows[] = ['day' => (string) $row['day'], 'views' => (int) $row['views']];
        }

        return $rows;
    }

    /**
     * Recipes ranked by recent views -- the "what's popular" leaderboard.
     * Recipes with no views at all don't appear. Ordered by the 30-day
     * count, then all-time as a tie-break.
     *
     * @return list<array{recipe_id: int, title: string, slug: string, views_7d: int, views_30d: int, views_all: int}>
     */
    public function topRecipes(int $limit = 50): array
    {
        $now = new DateTimeImmutable();
        $d7 = $now->modify('-6 days')->format('Y-m-d 00:00:00');
        $d30 = $now->modify('-29 days')->format('Y-m-d 00:00:00');

        $statement = $this->pdo->prepare(
            'SELECT r.id AS recipe_id, r.title, r.slug, '
            . 'SUM(CASE WHEN v.viewed_at >= :d7 THEN 1 ELSE 0 END) AS views_7d, '
            . 'SUM(CASE WHEN v.viewed_at >= :d30 THEN 1 ELSE 0 END) AS views_30d, '
            . 'COUNT(*) AS views_all '
            . 'FROM recipe_views v JOIN recipes r ON r.id = v.recipe_id '
            . 'GROUP BY r.id, r.title, r.slug '
            . 'ORDER BY views_30d DESC, views_all DESC, r.title ASC '
            . 'LIMIT :limit',
        );
        $statement->bindValue('d7', $d7);
        $statement->bindValue('d30', $d30);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        /** @var array{recipe_id: int|string, title: string, slug: string, views_7d: int|string, views_30d: int|string, views_all: int|string} $row */
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'recipe_id' => (int) $row['recipe_id'],
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'views_7d' => (int) $row['views_7d'],
                'views_30d' => (int) $row['views_30d'],
                'views_all' => (int) $row['views_all'],
            ];
        }

        return $rows;
    }
}
