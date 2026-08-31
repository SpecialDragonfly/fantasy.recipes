<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One row per human view of a published recipe detail page -- the raw
 * event log behind the in-house "popular recipes" metrics (admin at
 * /admin/metrics; App\Repository\RecipeViewRepository). Written from the
 * public recipe route in src/Routes/public.php.
 *
 * Bot hits (App\Http\CrawlerAudience) and admin views are NOT recorded, so
 * the counts reflect reader interest rather than crawler noise or the
 * admin's own review passes.
 *
 * Raw events, not a pre-aggregated daily table -- same reasoning as
 * login_events: portable plain INSERT, and per-day / per-recipe cuts stay
 * possible. FK ON DELETE CASCADE: deleting a recipe drops its view history.
 */
final class CreateRecipeViewsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('recipe_views')
            ->addColumn('recipe_id', 'integer', ['signed' => false])
            ->addColumn('viewed_at', 'datetime')
            ->addIndex(['viewed_at'])
            ->addIndex(['recipe_id'])
            ->addForeignKey('recipe_id', 'recipes', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
