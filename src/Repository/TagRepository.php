<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * A single, homogeneous tag type covers both functional/navigational tags
 * and whimsical easter-egg tags (spec.md -- Domain Model: Tag). Merge
 * (combining duplicate/synonymous tags) is explicitly deferred, not v1 --
 * deliberately not built here.
 *
 * @phpstan-type TagRow array{id: int, name: string}
 */
final class TagRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<TagRow>
     */
    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tags ORDER BY name ASC');
        $statement->execute();

        /** @var list<TagRow> */
        return $statement->fetchAll();
    }

    /**
     * @return TagRow|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tags WHERE id = :id');
        $statement->execute(['id' => $id]);

        /** @var TagRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return TagRow|null
     */
    public function findByName(string $name): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tags WHERE name = :name');
        $statement->execute(['name' => $name]);

        /** @var TagRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $name): int
    {
        $statement = $this->pdo->prepare('INSERT INTO tags (name) VALUES (:name)');
        $statement->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function rename(int $id, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE tags SET name = :name WHERE id = :id');
        $statement->execute(['name' => $name, 'id' => $id]);
    }

    /**
     * Cascades to recipe_tags via the FK (ON DELETE CASCADE) -- see
     * db/migrations/20260816120400_create_recipe_tags_table.php.
     */
    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM tags WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * @return list<TagRow>
     */
    public function tagsForRecipe(int $recipeId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT tags.* FROM tags '
            . 'INNER JOIN recipe_tags ON recipe_tags.tag_id = tags.id '
            . 'WHERE recipe_tags.recipe_id = :recipe_id '
            . 'ORDER BY tags.name ASC',
        );
        $statement->execute(['recipe_id' => $recipeId]);

        /** @var list<TagRow> */
        return $statement->fetchAll();
    }

    /**
     * Syncs the recipe_tags pivot to exactly the given tag ids
     * (delete-then-insert, wrapped in a transaction). Admins can freely
     * change which tags apply to a recipe (spec.md -- Domain Model: Tag).
     *
     * @param list<int> $tagIds
     */
    public function setTagsForRecipe(int $recipeId, array $tagIds): void
    {
        $this->pdo->beginTransaction();

        try {
            $delete = $this->pdo->prepare('DELETE FROM recipe_tags WHERE recipe_id = :recipe_id');
            $delete->execute(['recipe_id' => $recipeId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO recipe_tags (recipe_id, tag_id) VALUES (:recipe_id, :tag_id)',
            );

            foreach (array_unique($tagIds) as $tagId) {
                $insert->execute(['recipe_id' => $recipeId, 'tag_id' => $tagId]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Batch lookup for the /admin/recipes list page -- one query for
     * however many rows are on the page rather than one per row.
     *
     * @param list<int> $recipeIds
     * @return array<int, list<int>> tag ids keyed by recipe_id
     */
    public function tagIdsByRecipeIds(array $recipeIds): array
    {
        if ($recipeIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($recipeIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT recipe_id, tag_id FROM recipe_tags WHERE recipe_id IN ($placeholders)",
        );
        $statement->execute($recipeIds);

        $result = [];
        /** @var array{recipe_id: int|string, tag_id: int|string} $row */
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['recipe_id']][] = (int) $row['tag_id'];
        }

        return $result;
    }
}
