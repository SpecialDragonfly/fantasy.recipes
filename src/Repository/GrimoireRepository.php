<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * A single per-user bookmark list -- "recipes I want to try." No separate
 * "already made this" tracking (spec.md -- Domain Model: Wishlist
 * ("Grimoire")).
 *
 * @phpstan-type GrimoireEntryRow array{user_id: int, recipe_id: int, recipe_slug: string, title: string, created_at: string}
 */
final class GrimoireRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isInGrimoire(int $userId, int $recipeId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM grimoire_entries WHERE user_id = :user_id AND recipe_id = :recipe_id',
        );
        $statement->execute(['user_id' => $userId, 'recipe_id' => $recipeId]);

        return $statement->fetchColumn() !== false;
    }

    public function add(int $userId, int $recipeId): void
    {
        if ($this->isInGrimoire($userId, $recipeId)) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO grimoire_entries (user_id, recipe_id, created_at) '
            . 'VALUES (:user_id, :recipe_id, :created_at)',
        );
        $statement->execute([
            'user_id' => $userId,
            'recipe_id' => $recipeId,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function remove(int $userId, int $recipeId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM grimoire_entries WHERE user_id = :user_id AND recipe_id = :recipe_id',
        );
        $statement->execute(['user_id' => $userId, 'recipe_id' => $recipeId]);
    }

    /**
     * Joined to recipes so a route can render the Grimoire list directly,
     * without an N+1 lookup per entry.
     *
     * @return list<GrimoireEntryRow>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT grimoire_entries.user_id, grimoire_entries.recipe_id, '
            . 'recipes.slug AS recipe_slug, recipes.title, '
            . 'grimoire_entries.created_at '
            . 'FROM grimoire_entries '
            . 'INNER JOIN recipes ON recipes.id = grimoire_entries.recipe_id '
            . 'WHERE grimoire_entries.user_id = :user_id '
            . 'ORDER BY grimoire_entries.created_at DESC',
        );
        $statement->execute(['user_id' => $userId]);

        /** @var list<GrimoireEntryRow> */
        return $statement->fetchAll();
    }
}
