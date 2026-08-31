<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Pivot for recipes <-> tags (renamed from recipe_page_tags -- recipe_pages
 * no longer exists, see 20260816120300_create_recipes_table.php).
 */
final class CreateRecipeTagsTable extends AbstractMigration
{
    public function change(): void
    {
        // signed => false on both: they're FK columns onto the unsigned
        // default `id` PKs of recipes/tags -- a signedness mismatch fails
        // addForeignKey on MySQL with errno 150 (SQLite doesn't care).
        $this->table('recipe_tags', ['id' => false, 'primary_key' => ['recipe_id', 'tag_id']])
            ->addColumn('recipe_id', 'integer', ['signed' => false])
            ->addColumn('tag_id', 'integer', ['signed' => false])
            ->addForeignKey('recipe_id', 'recipes', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('tag_id', 'tags', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
