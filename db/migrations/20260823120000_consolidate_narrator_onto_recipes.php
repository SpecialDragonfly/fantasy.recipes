<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Collapses the narrator onto a single field. Previously `recipes.narrator`
 * (paired with narrator_recipe, the ritual-styled instructions) and
 * `stories.narrator` (paired with the Story body) were two independent
 * free-text columns that were, in practice, meant to name the same
 * storyteller -- nothing enforced that, so they could drift. Per an
 * explicit product decision, the narrator is now one admin-picked value
 * from a fixed roster (App\Recipe\Narrators) living only on
 * `recipes.narrator`; the Story's "Told by" credit reads that same column
 * via its recipe_id rather than storing its own copy.
 *
 * `stories.narrator` was NOT NULL, so every existing Story row has some
 * value there; `recipes.narrator` is nullable and, on a recipe whose
 * narrator-recipe form was never filled in, may still be null even though
 * its live Story already names a narrator. Backfill recipes.narrator from
 * the live story's narrator in that case before the column disappears, so
 * that data isn't silently lost -- an archived (non-live) story's narrator
 * has nowhere left to go and is dropped with the column, same as the
 * per-version narrator history generally (StoryRepository no longer
 * accepts/returns one at all after this).
 */
final class ConsolidateNarratorOntoRecipes extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'UPDATE recipes SET narrator = ('
            . 'SELECT stories.narrator FROM stories WHERE stories.id = recipes.story_id'
            . ') WHERE recipes.narrator IS NULL AND recipes.story_id IS NOT NULL',
        );

        $this->table('stories')->removeColumn('narrator')->update();
    }

    public function down(): void
    {
        // Data loss is inherent going backward -- the per-story narrator
        // this recolumn held is gone, so every existing row (there's no way
        // to tell live from archived here without recipes.story_id, and
        // this only needs to restore the column shape) comes back empty.
        $this->table('stories')
            ->addColumn('narrator', 'string', ['limit' => 255, 'default' => ''])
            ->update();
    }
}
