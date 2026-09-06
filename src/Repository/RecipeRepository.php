<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * One row per recipe for its entire lifecycle -- import, rewrite, AI-draft,
 * admin review, publish -- replacing the old three-table split
 * (scraped_recipes -> recipe_pages -> instruction_sets), where "rewriting"
 * a scraped row meant creating a brand new row elsewhere and linking back
 * to it. See db/migrations/20260816120300_create_recipes_table.php for the
 * full column-by-column rationale.
 *
 * @phpstan-type RecipeRow array{id: int, slug: string, title: string, origin: string|null, original_ingredients: string, original_instructions: string, narrator: string|null, narrator_recipe: string|null, story_id: int|null, status: string, created_at: string, updated_at: string, notified_at: string|null}
 */
final class RecipeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return RecipeRow|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipes WHERE id = :id');
        $statement->execute(['id' => $id]);

        /** @var RecipeRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return RecipeRow|null
     */
    public function findBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipes WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);

        /** @var RecipeRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Not a uniqueness check -- `origin` has no unique index (free text, can
     * describe a manual import with no real URL) -- just a best-effort
     * "have we already imported this exact URL/description" prompt for
     * recipe:import, so re-running it against the same link doesn't
     * silently create a duplicate without at least warning first.
     *
     * @return RecipeRow|null
     */
    public function findByOrigin(string $origin): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM recipes WHERE origin = :origin');
        $statement->execute(['origin' => $origin]);

        /** @var RecipeRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<int> $ids
     * @return list<RecipeRow>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare("SELECT * FROM recipes WHERE id IN ($placeholders)");
        $statement->execute($ids);

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * Created by recipe:import (or, historically, the admin "new recipe"
     * form) at status = 'draft' -- title starts as whatever mundane title
     * the import found; an admin overwrites it with the in-world fantasy
     * name before publish (spec.md -- Immersion Rules: no mundane title
     * anywhere in the UI once that happens).
     */
    public function create(
        string $slug,
        string $title,
        ?string $origin,
        string $originalIngredients,
        string $originalInstructions,
    ): int {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO recipes '
            . '(slug, title, origin, original_ingredients, original_instructions, status, created_at, updated_at) '
            . "VALUES (:slug, :title, :origin, :original_ingredients, :original_instructions, 'draft', :created_at, :updated_at)",
        );

        $statement->execute([
            'slug' => $slug,
            'title' => $title,
            'origin' => $origin,
            'original_ingredients' => $originalIngredients,
            'original_instructions' => $originalInstructions,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The admin "recipe details" form: slug and title -- status is
     * deliberately not one of this method's params. It's set solely by
     * setPublished() below, via the Publish/Unpublish quick-toggle
     * (recipe_edit.twig), so there's exactly one path that ever changes it
     * rather than two (this used to also take a $published flag from a
     * "Published" checkbox that duplicated the same toggle -- removed as
     * redundant). Everything else (ingredients/instructions, narrator/
     * narrator_recipe, story, tags) is saved through its own dedicated
     * method/form.
     */
    public function update(int $id, string $slug, string $title): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipes SET slug = :slug, title = :title, updated_at = :updated_at WHERE id = :id',
        );

        $statement->execute([
            'slug' => $slug,
            'title' => $title,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function setPublished(int $id, bool $published): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipes SET status = :status, updated_at = :updated_at WHERE id = :id',
        );

        $statement->execute([
            'status' => $published ? 'published' : 'draft',
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function updateOriginalIngredients(int $id, string $originalIngredients): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipes SET original_ingredients = :value, updated_at = :updated_at WHERE id = :id',
        );

        $statement->execute([
            'value' => $originalIngredients,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    public function updateOriginalInstructions(int $id, string $originalInstructions): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipes SET original_instructions = :value, updated_at = :updated_at WHERE id = :id',
        );

        $statement->execute([
            'value' => $originalInstructions,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    /**
     * Narrator assignment + the ritual-styled instructions in that
     * narrator's voice (was InstructionSet::TranslatedText) -- saved
     * together since narrator_recipe only makes sense alongside the
     * narrator who's telling it (spec.md -- Content Pipeline step 3).
     */
    public function updateNarratorRecipe(int $id, string $narrator, string $narratorRecipe): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipes SET narrator = :narrator, narrator_recipe = :narrator_recipe, updated_at = :updated_at '
            . 'WHERE id = :id',
        );

        $statement->execute([
            'narrator' => $narrator,
            'narrator_recipe' => $narratorRecipe,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    /**
     * Repoints recipes.story_id at whichever stories row is now live --
     * called by StoryRepository after create()/replace(), never set
     * directly by route code. Not a real FK (see the recipes migration's
     * docblock for why), so this is the only place this column is written.
     */
    public function setStoryId(int $id, ?int $storyId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recipes SET story_id = :story_id, updated_at = :updated_at WHERE id = :id',
        );

        $statement->execute([
            'story_id' => $storyId,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    /**
     * A real DELETE FROM -- recipe_tags, stories, and grimoire_entries are
     * all wired with ON DELETE CASCADE (see the migrations), so this one
     * statement takes the whole recipe with it.
     */
    public function delete(int $id): void
    {
        $statement = $this->pdo->prepare('DELETE FROM recipes WHERE id = :id');
        $statement->execute(['id' => $id]);
    }

    /**
     * Published recipes only, newest first -- for the public browse
     * listing.
     *
     * @return list<RecipeRow>
     */
    public function listPublished(int $limit = 24, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM recipes WHERE status = 'published' ORDER BY created_at DESC LIMIT :limit OFFSET :offset",
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * Published recipes that haven't been announced by a marketing email
     * yet (notified_at IS NULL). Oldest first -- if several are waiting,
     * they're announced together as one digest in publish order. See
     * db/migrations/20260831150000_create_recipe_email_queue.php.
     *
     * @return list<RecipeRow>
     */
    public function listPublishedAwaitingAnnouncement(): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM recipes WHERE status = 'published' AND notified_at IS NULL ORDER BY created_at ASC, id ASC",
        );
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * @param list<int> $ids
     */
    public function markAnnounced(array $ids, string $when): void
    {
        $this->setNotifiedAt($ids, $when);
    }

    /**
     * Undo markAnnounced() -- used when a queued campaign is cancelled, so
     * a later sweep picks these recipes up again.
     *
     * @param list<int> $ids
     */
    public function clearAnnounced(array $ids): void
    {
        $this->setNotifiedAt($ids, null);
    }

    /**
     * @param list<int> $ids
     */
    private function setNotifiedAt(array $ids, ?string $when): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE recipes SET notified_at = ? WHERE id IN ($placeholders)",
        );
        $statement->execute([$when, ...$ids]);
    }

    public function countPublished(): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM recipes WHERE status = 'published'");
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Published recipes tagged with a given tag id, newest first -- backs
     * the tag-filtered browse view.
     *
     * @return list<RecipeRow>
     */
    public function listPublishedByTag(int $tagId, int $limit = 24, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT recipes.* FROM recipes '
            . 'INNER JOIN recipe_tags ON recipe_tags.recipe_id = recipes.id '
            . "WHERE recipe_tags.tag_id = :tag_id AND recipes.status = 'published' "
            . 'ORDER BY recipes.created_at DESC LIMIT :limit OFFSET :offset',
        );
        $statement->bindValue('tag_id', $tagId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * Published recipes matching a free-text search over the recipe title
     * and its individual (newline-separated) ingredients, newest first --
     * backs the search box on the public /recipes browse listing. Every
     * term must match (AND); a term matches if it's a substring of the
     * title OR of any one ingredient. Composes with an optional single-tag
     * filter, so search + tag narrow together. Same shared-WHERE-builder
     * shape as adminListFilter(), so this and countPublishedBrowse() can't
     * drift apart.
     *
     * @param list<string> $terms search terms, already trimmed and non-empty
     * @return list<RecipeRow>
     */
    public function listPublishedBrowse(array $terms, ?int $tagId = null, int $limit = 24, int $offset = 0): array
    {
        [$where, $params] = self::publishedBrowseFilter($terms, $tagId);

        $statement = $this->pdo->prepare(
            "SELECT recipes.* FROM recipes $where "
            . 'ORDER BY recipes.created_at DESC LIMIT :limit OFFSET :offset',
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * Total matching listPublishedBrowse()'s same filters -- feeds the
     * browse listing's pagination controls.
     *
     * @param list<string> $terms search terms, already trimmed and non-empty
     */
    public function countPublishedBrowse(array $terms, ?int $tagId = null): int
    {
        [$where, $params] = self::publishedBrowseFilter($terms, $tagId);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM recipes $where");
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Published recipes that call for ANY of the given reagents
     * (ingredients), ranked by how many distinct reagents each one matches
     * -- a recipe drawing on 3 of the searched reagents outranks one
     * drawing on 2, whichever specific reagents those were; `created_at
     * DESC` (newest first) breaks ties. Backs the "Conjure a Recipe" page
     * (GET /conjure): the reader lists what they have to hand and gets the
     * dishes that make the most of it, best fit first.
     *
     * A reagent matches when it's a substring of the recipe title OR of the
     * newline-separated `original_ingredients` text blob (there is no
     * ingredients table) -- the same per-term shape as
     * publishedBrowseFilter(). % and _ in a reagent are escaped so they
     * match literally (convention from src/Search/LikeSearch.php); plain
     * LIKE behaves identically on MySQL and SQLite. Named string params are
     * never reused across the statement (same reason the browse filter
     * uses one param per column) -- each reagent gets its own WHERE and
     * ORDER BY placeholders bound to the same pattern. An empty $reagents
     * returns [] without touching the database.
     *
     * @param list<string> $reagents reagents to look for, already trimmed and non-empty
     * @return list<RecipeRow>
     */
    public function listPublishedByReagents(array $reagents, int $limit = 24, int $offset = 0): array
    {
        if ($reagents === []) {
            return [];
        }

        $matchConditions = [];
        $rankTerms = [];
        $params = [];

        foreach ($reagents as $i => $reagent) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $reagent) . '%';
            $matchConditions[] = "(recipes.title LIKE :where_title_$i OR recipes.original_ingredients LIKE :where_ing_$i)";
            $rankTerms[] = "CASE WHEN (recipes.title LIKE :rank_title_$i "
                . "OR recipes.original_ingredients LIKE :rank_ing_$i) THEN 1 ELSE 0 END";
            $params["where_title_$i"] = $like;
            $params["where_ing_$i"] = $like;
            $params["rank_title_$i"] = $like;
            $params["rank_ing_$i"] = $like;
        }

        $where = implode(' OR ', $matchConditions);
        $rank = implode(' + ', $rankTerms);

        $statement = $this->pdo->prepare(
            "SELECT recipes.* FROM recipes "
            . "WHERE recipes.status = 'published' AND ($where) "
            . "ORDER BY ($rank) DESC, recipes.created_at DESC LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * Every recipe regardless of status, optionally filtered by a title
     * search, a single tag, and/or status ('draft'/'published') -- feeds
     * /admin/recipes' search bar + filters. Same shared-WHERE-builder shape
     * used throughout this project, so the list and count queries' logic
     * (and therefore their result sets) can't drift apart.
     *
     * @return list<RecipeRow>
     */
    public function listAllFiltered(
        int $limit = 50,
        int $offset = 0,
        ?string $titleSearch = null,
        ?int $tagId = null,
        ?string $status = null,
    ): array {
        [$where, $params] = self::adminListFilter($titleSearch, $tagId, $status);

        $statement = $this->pdo->prepare(
            "SELECT recipes.* FROM recipes $where "
            . 'ORDER BY recipes.slug ASC LIMIT :limit OFFSET :offset',
        );
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * Total matching listAllFiltered()'s same filters -- feeds the
     * /admin/recipes pagination controls.
     */
    public function countAllFiltered(?string $titleSearch = null, ?int $tagId = null, ?string $status = null): int
    {
        [$where, $params] = self::adminListFilter($titleSearch, $tagId, $status);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM recipes $where");
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Count of recipes still at status = 'draft' -- feeds the admin
     * dashboard's "still needs work" summary figure, same role
     * draftedCount/pendingRewriteCount used to play before the pipeline
     * collapsed onto one status column.
     */
    public function countDrafts(): int
    {
        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM recipes WHERE status = 'draft'");
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * Every recipe, walked in fixed-size pages ordered by id -- an
     * id-cursor rather than OFFSET so a full sweep
     * (recipe:normalize-temperatures) stays correct even though it writes
     * back to rows it has already passed: an UPDATE never changes a row's
     * id or its position in this ordering, so nothing gets skipped or
     * re-seen the way OFFSET-based paging could if a batch's writes
     * changed how many rows sort before the next page's cursor.
     *
     * @return list<RecipeRow>
     */
    public function listBatchAfterId(int $afterId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM recipes WHERE id > :after_id ORDER BY id ASC LIMIT :limit',
        );
        $statement->bindValue('after_id', $afterId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        /** @var list<RecipeRow> */
        return $statement->fetchAll();
    }

    /**
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function adminListFilter(?string $titleSearch, ?int $tagId, ?string $status): array
    {
        $joins = [];
        $conditions = [];
        $params = [];

        if ($titleSearch !== null && $titleSearch !== '') {
            $conditions[] = 'recipes.title LIKE :title_search';
            $params['title_search'] = '%' . $titleSearch . '%';
        }

        if ($tagId !== null) {
            $joins[] = 'INNER JOIN recipe_tags rt_filter '
                . 'ON rt_filter.recipe_id = recipes.id AND rt_filter.tag_id = :tag_id';
            $params['tag_id'] = $tagId;
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'recipes.status = :status';
            $params['status'] = $status;
        }

        $where = implode(' ', $joins);
        if ($conditions !== []) {
            $where .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return [$where, $params];
    }

    /**
     * Shared WHERE builder for listPublishedBrowse() + countPublishedBrowse().
     * Always constrains to status = 'published'; adds one
     * "(title LIKE :term OR original_ingredients LIKE :term)" condition per
     * search term (AND-ed together); and, when a tag id is given, the same
     * INNER JOIN recipe_tags used by adminListFilter(). % and _ in a term are
     * escaped so they match literally (convention from src/Search/LikeSearch.php).
     * Plain LIKE works identically on MySQL and SQLite, so no FULLTEXT / driver
     * split is needed here.
     *
     * @param list<string> $terms
     * @return array{0: string, 1: array<string, string|int>}
     */
    private static function publishedBrowseFilter(array $terms, ?int $tagId): array
    {
        $joins = [];
        $conditions = ["recipes.status = 'published'"];
        $params = [];

        if ($tagId !== null) {
            $joins[] = 'INNER JOIN recipe_tags rt_filter '
                . 'ON rt_filter.recipe_id = recipes.id AND rt_filter.tag_id = :tag_id';
            $params['tag_id'] = $tagId;
        }

        foreach ($terms as $i => $term) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
            $conditions[] = "(recipes.title LIKE :title_$i OR recipes.original_ingredients LIKE :ingredients_$i)";
            $params["title_$i"] = $like;
            $params["ingredients_$i"] = $like;
        }

        $where = implode(' ', $joins) . ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }
}
