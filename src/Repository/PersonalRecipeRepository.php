<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * A user's own private recipes -- see
 * db/migrations/20260824090000_create_personal_recipes_table.php for why
 * this is a separate table from RecipeRepository's `recipes` rather than a
 * row in it. Every read/write here takes the acting user's id and folds it
 * into the query itself (WHERE user_id = :user_id), not just as a
 * findById()-then-check in the caller -- privacy is the entire point of
 * this table, so ownership is enforced at the one place every access has
 * to go through, not left to every route remembering to check it.
 *
 * @phpstan-type PersonalRecipeRow array{id: int, user_id: int, title: string, ingredients: string, instructions: string, created_at: string, updated_at: string}
 */
final class PersonalRecipeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Null both when the id doesn't exist at all and when it belongs to a
     * different user -- deliberately indistinguishable to the caller (a
     * 404 either way), so this can't be used to probe whether some other
     * user has a personal recipe with a given id.
     *
     * @return PersonalRecipeRow|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM personal_recipes WHERE id = :id AND user_id = :user_id',
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        /** @var PersonalRecipeRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<PersonalRecipeRow>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            // `id DESC` tiebreaks rows created within the same
            // second-resolution `created_at` (datetime column, no
            // sub-second precision) -- same reasoning as
            // StoryRepository::listArchived().
            'SELECT * FROM personal_recipes WHERE user_id = :user_id ORDER BY created_at DESC, id DESC',
        );
        $statement->execute(['user_id' => $userId]);

        /** @var list<PersonalRecipeRow> */
        return $statement->fetchAll();
    }

    public function create(int $userId, string $title, string $ingredients, string $instructions): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO personal_recipes (user_id, title, ingredients, instructions, created_at, updated_at) '
            . 'VALUES (:user_id, :title, :ingredients, :instructions, :created_at, :updated_at)',
        );
        $statement->execute([
            'user_id' => $userId,
            'title' => $title,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A no-op (not an error) if $id doesn't belong to $userId -- the
     * user_id in the WHERE clause is what makes this safe to call with a
     * caller-supplied id straight off the URL: there's no row to update
     * that isn't already theirs.
     */
    public function update(int $id, int $userId, string $title, string $ingredients, string $instructions): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE personal_recipes '
            . 'SET title = :title, ingredients = :ingredients, instructions = :instructions, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id',
        );
        $statement->execute([
            'title' => $title,
            'ingredients' => $ingredients,
            'instructions' => $instructions,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
            'user_id' => $userId,
        ]);
    }

    /**
     * Same no-op-if-not-yours guarantee as update() -- see its docblock.
     */
    public function delete(int $id, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM personal_recipes WHERE id = :id AND user_id = :user_id',
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);
    }
}
