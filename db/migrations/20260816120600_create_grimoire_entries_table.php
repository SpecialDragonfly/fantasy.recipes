<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A single per-user bookmark list -- "recipes I want to try." No separate
 * "already made this" tracking (spec.md -- Domain Model: Wishlist
 * ("Grimoire")). Repointed at recipes.id (recipe_id) -- recipe_pages no
 * longer exists.
 */
final class CreateGrimoireEntriesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('grimoire_entries', ['id' => false, 'primary_key' => ['user_id', 'recipe_id']])
            ->addColumn('user_id', 'integer')
            ->addColumn('recipe_id', 'integer')
            ->addColumn('created_at', 'datetime')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('recipe_id', 'recipes', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
