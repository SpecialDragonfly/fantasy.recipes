<?php

declare(strict_types=1);

namespace App\Search;

use PDO;

/**
 * Portability shim, NOT the "real" search experience: SQLite has no
 * FULLTEXT equivalent (see architecture.md -- Search / Testing Strategy),
 * but local/dev runs against SQLite need *something* working for browsing
 * recipes end-to-end outside the MySQL-only integration suite. This is a
 * judgement call to make local dev functional, not a spec.md requirement --
 * plain LIKE '%term%' matching, no ranking, no natural-language parsing.
 * Bound in place of MysqlFulltextSearch only when settings.db.driver !==
 * 'mysql' -- see src/bootstrap.php.
 */
final class LikeSearch implements RecipeSearch
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
            . 'AND (title LIKE :term OR original_ingredients LIKE :term2 '
            . 'OR original_instructions LIKE :term3 OR narrator_recipe LIKE :term4) '
            . 'ORDER BY title ASC '
            . 'LIMIT :limit OFFSET :offset',
        );

        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
        $statement->bindValue('term', $term, PDO::PARAM_STR);
        $statement->bindValue('term2', $term, PDO::PARAM_STR);
        $statement->bindValue('term3', $term, PDO::PARAM_STR);
        $statement->bindValue('term4', $term, PDO::PARAM_STR);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<array{id: int, slug: string, title: string}> */
        return $statement->fetchAll();
    }
}
