<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$root = __DIR__;

// Two independent env profiles, loaded into separate arrays rather than via
// $_ENV -- .env drives development/production (MySQL, Docker), .env.testing
// drives the "testing" Phinx environment (SQLite, no Docker/MySQL needed).
// Keeping them separate means having both files present locally can't let
// one clobber the other's values. See architecture.md -- Testing Strategy.
$devVars = file_exists($root . '/.env')
    ? Dotenv::createImmutable($root, '.env')->safeLoad()
    : [];

$testVars = file_exists($root . '/.env.testing')
    ? Dotenv::createImmutable($root, '.env.testing')->safeLoad()
    : [];

$mysqlConnection = static fn (array $env): array => [
    'adapter' => 'mysql',
    'host' => $env['DB_HOST'] ?? '127.0.0.1',
    'name' => $env['DB_DATABASE'] ?? 'fantasy_recipes',
    'user' => $env['DB_USERNAME'] ?? '',
    'pass' => $env['DB_PASSWORD'] ?? '',
    'port' => $env['DB_PORT'] ?? '3306',
    'charset' => 'utf8mb4',
];

$sqliteConnection = static fn (array $env): array => [
    'adapter' => 'sqlite',
    'name' => $env['DB_SQLITE_PATH'] ?? ($root . '/storage/testing.sqlite'),
    // Without this Phinx appends '.sqlite3' to 'name', which would put the
    // migrations in a different file than the one src/bootstrap.php opens.
    'suffix' => '',
];

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment' => ($devVars['APP_ENV'] ?? null) === 'production' ? 'production' : 'development',
        'development' => $mysqlConnection($devVars),
        'production' => $mysqlConnection($devVars),
        // Always SQLite, regardless of what's in .env -- run with
        // `vendor/bin/phinx migrate -e testing`.
        'testing' => $sqliteConnection($testVars),
    ],
];
