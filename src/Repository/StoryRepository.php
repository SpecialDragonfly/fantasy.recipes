<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * Absorbs the old story_archive table -- a recipe can have many stories
 * rows over time, but only one "live" one: recipes.story_id
 * (db/migrations/20260816120300_create_recipes_table.php) points at it,
 * and every other row for that recipe_id has a non-null `archived_at`.
 * History/reversion is kept even without StorySubmission (removed
 * entirely, see db/migrations/20260816120500_create_stories_table.php) --
 * an admin or an AI regeneration overwriting a story should still be
 * revertible.
 *
 * recipes.story_id is deliberately not a real FOREIGN KEY (see that
 * migration's docblock), so this repository is the only place it gets
 * written -- create()/replace() below UPDATE recipes.story_id directly by
 * raw SQL rather than depending on RecipeRepository, to avoid a
 * repository-to-repository dependency for what's really one invariant
 * (recipes.story_id always points at this recipe's one live story).
 *
 * @phpstan-type StoryRow array{id: int, recipe_id: int, narrator: string, body: string, author_user_id: int|null, created_at: string, archived_at: string|null}
 */
final class StoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return StoryRow|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM stories WHERE id = :id');
        $statement->execute(['id' => $id]);

        /** @var StoryRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * First Story for a recipe that has none yet. Use replace() once a live
     * Story already exists (this doesn't check -- callers already know,
     * same as before: the admin "add a Story" form branches on whether
     * recipes.story_id is already set).
     */
    public function create(int $recipeId, string $narrator, string $body, ?int $authorUserId): int
    {
        $this->pdo->beginTransaction();

        try {
            $newId = self::insert($this->pdo, $recipeId, $narrator, $body, $authorUserId);
            self::pointRecipeAt($this->pdo, $recipeId, $newId);

            $this->pdo->commit();

            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * In-place correction (a typo, a rewording) to the live Story -- does
     * NOT archive the previous text like replace() does. A direct fix to
     * an already-live Story isn't a genuine replacement, and archiving on
     * every small edit would just fill the table with noise. Author stays
     * whoever it already was -- fixing a typo doesn't reassign authorship
     * credit.
     */
    public function update(int $id, string $narrator, string $body): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE stories SET narrator = :narrator, body = :body WHERE id = :id',
        );
        $statement->execute(['narrator' => $narrator, 'body' => $body, 'id' => $id]);
    }

    /**
     * Swaps in a genuinely different live Story (a new AI draft, a
     * different author) and archives the previous one with an archival
     * timestamp instead of deleting it, for possible reversion. If there is
     * no existing live Story yet, this just creates one (no archiving
     * needed) -- same fallback InstructionSetRepository::setDraftTranslation()
     * used to play for a recipe with nothing drafted yet.
     */
    public function replace(int $recipeId, string $narrator, string $body, ?int $authorUserId): int
    {
        $this->pdo->beginTransaction();

        try {
            $current = $this->pdo->prepare('SELECT story_id FROM recipes WHERE id = :recipe_id');
            $current->execute(['recipe_id' => $recipeId]);
            $currentStoryId = $current->fetchColumn();

            if ($currentStoryId !== false && $currentStoryId !== null) {
                $archive = $this->pdo->prepare(
                    'UPDATE stories SET archived_at = :archived_at WHERE id = :id',
                );
                $archive->execute([
                    'archived_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'id' => (int) $currentStoryId,
                ]);
            }

            $newId = self::insert($this->pdo, $recipeId, $narrator, $body, $authorUserId);
            self::pointRecipeAt($this->pdo, $recipeId, $newId);

            $this->pdo->commit();

            return $newId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Archived Stories for a recipe, newest first -- admin-only, never
     * shown to guests/users.
     *
     * @return list<StoryRow>
     */
    public function listArchived(int $recipeId): array
    {
        $statement = $this->pdo->prepare(
            // `id DESC` tiebreaks rows archived within the same
            // second-resolution `archived_at` (datetime column, no
            // sub-second precision) -- otherwise their relative order is
            // implementation-defined.
            'SELECT * FROM stories WHERE recipe_id = :recipe_id AND archived_at IS NOT NULL '
            . 'ORDER BY archived_at DESC, id DESC',
        );
        $statement->execute(['recipe_id' => $recipeId]);

        /** @var list<StoryRow> */
        return $statement->fetchAll();
    }

    private static function insert(PDO $pdo, int $recipeId, string $narrator, string $body, ?int $authorUserId): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO stories (recipe_id, narrator, body, author_user_id, created_at) '
            . 'VALUES (:recipe_id, :narrator, :body, :author_user_id, :created_at)',
        );
        $statement->execute([
            'recipe_id' => $recipeId,
            'narrator' => $narrator,
            'body' => $body,
            'author_user_id' => $authorUserId,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function pointRecipeAt(PDO $pdo, int $recipeId, int $storyId): void
    {
        $statement = $pdo->prepare('UPDATE recipes SET story_id = :story_id WHERE id = :id');
        $statement->execute(['story_id' => $storyId, 'id' => $recipeId]);
    }
}
