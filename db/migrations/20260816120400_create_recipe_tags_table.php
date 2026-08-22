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
        $this->table('recipe_tags', ['id' => false, 'primary_key' => ['recipe_id', 'tag_id']])
            ->addColumn('recipe_id', 'integer')
            ->addColumn('tag_id', 'integer')
            ->addForeignKey('recipe_id', 'recipes', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('tag_id', 'tags', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
