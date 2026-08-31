<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PDO;

/**
 * Provisions a fresh, isolated `sqlite::memory:` connection with the same
 * table shape as the db/migrations/ baseline, for repository/workflow tests
 * that need a real PDO connection without touching storage/testing.sqlite
 * (shared, ambient state) or depending on Phinx's migration runner at test
 * time.
 *
 * Deliberately hand-written raw DDL rather than reusing the Phinx migration
 * classes directly: Phinx's AbstractMigration expects to run inside its own
 * Manager/adapter machinery, not be instantiated standalone, and the fast
 * unit suite (architecture.md -- Testing Strategy) is meant to have zero
 * external moving parts. Keep this in sync with the real migrations if the
 * schema changes -- there is no single source of truth shared between them.
 *
 * The MySQL-only FULLTEXT INDEX from the recipes migration is intentionally
 * omitted (sqlite has no equivalent, and MysqlFulltextSearch is covered by
 * a separate MySQL integration suite per architecture.md, not here).
 */
final class InMemoryDatabase
{
    /**
     * @param PDO|null $pdo An already-constructed PDO to schema onto instead
     *     of a plain sqlite::memory: connection -- lets a test hand in a PDO
     *     subclass (e.g. one that throws on a specific statement, to test
     *     transaction rollback) while still getting the same baseline schema
     *     as every other repository test.
     */
    public static function create(?PDO $pdo = null): PDO
    {
        $pdo ??= new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Off by default per sqlite connection -- see the identical fix and
        // comment in src/bootstrap.php's PDO::class factory.
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(64) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(16) NOT NULL DEFAULT \'user\',
                created_at DATETIME NOT NULL,
                marketing_opt_in BOOLEAN NOT NULL DEFAULT 0,
                marketing_opt_in_at DATETIME,
                unsubscribe_token VARCHAR(32),
                UNIQUE (username),
                UNIQUE (email),
                UNIQUE (unsubscribe_token)
            )',
        );

        $pdo->exec(
            'CREATE TABLE password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash VARCHAR(255) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        $pdo->exec(
            'CREATE TABLE tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(64) NOT NULL,
                UNIQUE (name)
            )',
        );

        // See db/migrations/20260816120300_create_recipes_table.php --
        // story_id is deliberately not a real FOREIGN KEY (circular with
        // stories.recipe_id), matching the real schema.
        $pdo->exec(
            'CREATE TABLE recipes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug VARCHAR(191) NOT NULL,
                title VARCHAR(255) NOT NULL,
                origin TEXT NULL,
                original_ingredients TEXT NOT NULL,
                original_instructions TEXT NOT NULL,
                narrator TEXT NULL,
                narrator_recipe TEXT NULL,
                story_id INTEGER NULL,
                status VARCHAR(16) NOT NULL DEFAULT \'draft\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE (slug)
            )',
        );

        $pdo->exec(
            'CREATE TABLE recipe_tags (
                recipe_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (recipe_id, tag_id),
                FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
                FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
            )',
        );

        $pdo->exec(
            'CREATE TABLE stories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                recipe_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                author_user_id INTEGER NULL,
                created_at DATETIME NOT NULL,
                archived_at DATETIME NULL,
                FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE,
                FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
            )',
        );
        $pdo->exec('CREATE INDEX idx_stories_recipe_id ON stories(recipe_id)');

        $pdo->exec(
            'CREATE TABLE grimoire_entries (
                user_id INTEGER NOT NULL,
                recipe_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, recipe_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (recipe_id) REFERENCES recipes(id) ON DELETE CASCADE
            )',
        );

        // See db/migrations/20260824090000_create_personal_recipes_table.php
        // -- a user's own private recipes, deliberately separate from
        // `recipes` rather than a row in it.
        $pdo->exec(
            'CREATE TABLE personal_recipes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title VARCHAR(255) NOT NULL,
                ingredients TEXT NOT NULL,
                instructions TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );
        $pdo->exec('CREATE INDEX idx_personal_recipes_user_id ON personal_recipes(user_id)');

        return $pdo;
    }

    /**
     * Convenience: inserts a minimal user row and returns its id. Kept here
     * rather than duplicated across test classes that just need "a user to
     * hang a foreign key off of".
     */
    public static function seedUser(PDO $pdo, string $username = 'testuser', string $role = 'user'): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, created_at) '
            . "VALUES (:username, :username || '@example.com', 'x', :role, '2026-01-01 00:00:00')",
        );
        $statement->execute(['username' => $username, 'role' => $role]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Convenience: inserts a recipe with ingredients/instructions and,
     * optionally, a narrator_recipe -- covers what repository/search tests
     * need without every test hand-rolling the INSERT.
     */
    public static function seedRecipe(
        PDO $pdo,
        string $slug = 'test-recipe',
        bool $published = true,
        string $originalInstructions = 'Boil the beef.',
        ?string $narratorRecipe = 'Boil the dragonflesh over kiln-fire.',
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO recipes '
            . '(slug, title, original_ingredients, original_instructions, narrator_recipe, status, created_at, updated_at) '
            . "VALUES (:slug, 'Test Recipe', 'Beef.', :original_instructions, :narrator_recipe, :status, "
            . "'2026-01-01 00:00:00', '2026-01-01 00:00:00')",
        );
        $statement->execute([
            'slug' => $slug,
            'original_instructions' => $originalInstructions,
            'narrator_recipe' => $narratorRecipe,
            'status' => $published ? 'published' : 'draft',
        ]);

        return (int) $pdo->lastInsertId();
    }
}
