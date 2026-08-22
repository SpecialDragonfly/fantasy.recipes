<?php

declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Production RecipeSearch, backed by the FULLTEXT INDEX on
 * recipes(title, original_ingredients, original_instructions,
 * narrator_recipe) -- see
 * db/migrations/20260816120300_create_recipes_table.php, which creates
 * that index only under the mysql adapter (SQLite has no FULLTEXT
 * equivalent). Never exercised by the fast local SQLite-backed PHPUnit
 * suite -- see architecture.md -- Search / Testing Strategy; this class is
 * covered by a separate MySQL integration suite instead, and unit tests
 * elsewhere stub the RecipeSearch interface.
 */
final class MysqlFulltextSearch implements RecipeSearch
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{id: int, slug: string, title: string}>
     */
    public function search(string $query, int $limit = 20, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, slug, title '
            . 'FROM recipes '
            . "WHERE status = 'published' "
            . 'AND MATCH(title, original_ingredients, original_instructions, narrator_recipe) '
            . 'AGAINST (:query IN NATURAL LANGUAGE MODE) '
            . 'ORDER BY MATCH(title, original_ingredients, original_instructions, narrator_recipe) '
            . 'AGAINST (:query2 IN NATURAL LANGUAGE MODE) DESC '
            . 'LIMIT :limit OFFSET :offset',
        );

        $statement->bindValue('query', $query, PDO::PARAM_STR);
        $statement->bindValue('query2', $query, PDO::PARAM_STR);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{id: int, slug: string, title: string}> */
        return $statement->fetchAll();
    }
}
