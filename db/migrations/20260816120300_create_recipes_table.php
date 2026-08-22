<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The single-table recipe lifecycle: one row from import through to
 * publish, replacing the old three-table split (scraped_recipes ->
 * recipe_pages -> instruction_sets) where "rewriting" a scraped row meant
 * creating an entirely new row elsewhere and linking back to it. Now a
 * recipe is one row throughout:
 *
 *  - `origin` -- free text, not a unique/URL-only column: a source URL for
 *    an imported recipe (recipe:import), or a plain description for a
 *    manually-entered one (see ideas.md: "manually import from BBC Good
 *    Food, Hello Fresh, Riverford"). No dedupe constraint at the DB level.
 *  - `title` -- one evolving field, not two. Starts as the plain/mundane
 *    title an import found, gets overwritten in place by an admin with the
 *    in-world fantasy name before publish. No mundane title survives
 *    anywhere once that happens (spec.md -- Immersion Rules: "no mundane
 *    title anywhere in the UI").
 *  - `original_ingredients` / `original_instructions` -- kept as two
 *    fields, not one combined "OriginalText" blob, so ingredients (mostly
 *    carried through near-verbatim -- a listing of ingredients is a fact,
 *    not an expression) and instructions (which get an admin rewrite) stay
 *    independently editable.
 *  - `narrator` / `narrator_recipe` -- the assigned narrator persona
 *    (spec.md -- Content Pipeline step 3, see personas.md) and the
 *    ritual-styled instructions in that narrator's voice (was
 *    InstructionSet::TranslatedText).
 *  - `story_id` -- points at the currently-live row in `stories`
 *    (20260816120500_create_stories_table.php). Deliberately NOT a real
 *    FOREIGN KEY: stories.recipe_id already points back the other way, and
 *    Phinx/SQLite can't create two tables with a live circular FK in one
 *    migration each. Referential integrity here is application-level only
 *    (RecipeRepository/StoryRepository).
 *  - `status` -- collapsed to just 'draft' | 'published'. The old
 *    three-state translation_status ('none'/'drafted'/'reviewed') is gone;
 *    a recipe is simply a draft (import, rewrite, AI-draft, and admin
 *    review all happen while it's a draft, edited in place) until an admin
 *    publishes it.
 */
final class CreateRecipesTable extends AbstractMigration
{
    public function up(): void
    {
        $isMysql = $this->getAdapter()->getAdapterType() === 'mysql';

        $this->table('recipes', ['id' => 'id'])
            ->addColumn('slug', 'string', ['limit' => 191])
            ->addColumn('title', 'string', ['limit' => 255])
            ->addColumn('origin', 'text', ['null' => true])
            ->addColumn('original_ingredients', 'text')
            ->addColumn('original_instructions', 'text')
            ->addColumn('narrator', 'text', ['null' => true])
            ->addColumn('narrator_recipe', 'text', ['null' => true])
            ->addColumn('story_id', 'integer', ['null' => true])
            // 'draft' | 'published' -- VARCHAR not ENUM, same portability
            // reasoning as every other status column in this project (see
            // architecture.md -- Testing Strategy).
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'draft'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['slug'], ['unique' => true])
            ->addIndex(['story_id'])
            ->addIndex(['status'])
            ->create();

        if ($isMysql) {
            // Full-text search runs across title + both the mundane and
            // ritual instruction text, so a mundane term ("beef") and a
            // ritual term ("dragonflesh") both surface the same recipe --
            // see spec.md -- Search. MySQL-only, same guard as always
            // (SQLite has no FULLTEXT equivalent -- src/Search/LikeSearch.php
            // is the local/test fallback).
            $this->execute(
                'ALTER TABLE recipes ADD FULLTEXT INDEX ft_recipes_text '
                . '(title, original_ingredients, original_instructions, narrator_recipe)',
            );
        }
    }

    public function down(): void
    {
        $this->table('recipes')->drop()->save();
    }
}
