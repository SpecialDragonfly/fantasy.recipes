<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A user's own private recipes -- the Grimoire's second feature alongside
 * the existing bookmark list (grimoire_entries,
 * db/migrations/20260816120600_create_grimoire_entries_table.php).
 * Deliberately a separate table from `recipes`
 * (db/migrations/20260816120300_create_recipes_table.php), not a row in it
 * with an owner column: `recipes` carries the whole curated-fantasy
 * pipeline (narrator, narrator_recipe, Story, tags, draft/published
 * status, full-text search, sitemap/JSON-LD, the admin recipe list) built
 * for site-wide published content, and a private personal recipe has no
 * business anywhere near any of that -- there's no query across the site
 * that touches this table, so there's no query that has to remember to
 * exclude someone else's private rows to avoid leaking them. Privacy here
 * is structural, not a filter an admin dashboard or search index has to
 * keep getting right.
 *
 * No `slug` -- there's no public URL for a private recipe, it's addressed
 * by id on a route gated to its own owner (see PersonalRecipeRepository).
 * No narrator/Story/tags/status -- just title, ingredients, instructions,
 * the plain functional shape recipes.original_ingredients/
 * original_instructions already use for "the mundane truth", since a
 * personal recipe has no ritual-styled counterpart to translate into.
 */
final class CreatePersonalRecipesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('personal_recipes', ['id' => 'id'])
            ->addColumn('user_id', 'integer')
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('ingredients', 'text')
            ->addColumn('instructions', 'text')
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['user_id'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
