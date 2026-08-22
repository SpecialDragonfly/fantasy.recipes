<?php

declare(strict_types=1);

use App\Mail\LogMailer;
use App\Mail\Mailer;
use App\Search\LikeSearch;
use App\Search\MysqlFulltextSearch;
use App\Search\RecipeSearch;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

// Shared by both public/index.php (web) and bin/console (CLI) so the two
// entry points build the exact same container. See architecture.md --
// Application Architecture.

$root = dirname(__DIR__);

// Read APP_ENV from wherever it's actually set: $_ENV only reflects the OS
// environment if php.ini's variables_order includes "E", which it doesn't
// by default (typically "GPCS") -- so a shell-exported `APP_ENV=testing`
// (as used by the PHP built-in dev server and CI) is invisible to $_ENV but
// visible to getenv(). Without this fallback, the file-selection check below
// silently loads .env (the mysql/production config) instead of .env.testing,
// and PDO fails with a confusing "could not find driver" instead of a clear
// signal that the wrong environment file was loaded.
$appEnvFromGetenv = getenv('APP_ENV');
$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ($appEnvFromGetenv !== false ? $appEnvFromGetenv : null);

$envFile = $appEnv === 'testing' ? '.env.testing' : '.env';
if (file_exists($root . '/' . $envFile)) {
    Dotenv::createImmutable($root, $envFile)->safeLoad();
}

$builder = new ContainerBuilder();

$builder->addDefinitions([
    'settings' => [
        'app_env' => $_ENV['APP_ENV'] ?? 'production',
        'app_debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'app_url' => $_ENV['APP_URL'] ?? 'http://localhost',
        'db' => [
            // 'mysql' in Docker/production, 'sqlite' for local tests --
            // see architecture.md -- Testing Strategy.
            'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'database' => $_ENV['DB_DATABASE'] ?? 'fantasy_recipes',
            'username' => $_ENV['DB_USERNAME'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            // Resolved against $root, not the process CWD: the PHP built-in
            // dev server chdir()s into the docroot (public/) per request, so
            // a relative path here would silently resolve under public/
            // instead of the project root on every web request (CLI/phpunit
            // runs wouldn't show the bug, since their CWD is the project
            // root already -- this only breaks under the dev server/fpm).
            'sqlite_path' => isset($_ENV['DB_SQLITE_PATH']) && $_ENV['DB_SQLITE_PATH'] !== ''
                ? (str_starts_with($_ENV['DB_SQLITE_PATH'], '/')
                    ? $_ENV['DB_SQLITE_PATH']
                    : $root . '/' . preg_replace('#^\./#', '', $_ENV['DB_SQLITE_PATH']))
                : $root . '/storage/testing.sqlite',
        ],
    ],

    PDO::class => function (ContainerInterface $c): PDO {
        $db = $c->get('settings')['db'];

        if ($db['driver'] === 'sqlite') {
            $pdo = new PDO('sqlite:' . $db['sqlite_path']);
            // SQLite defaults foreign_keys OFF per-connection -- without
            // this, every `ON DELETE CASCADE` in the schema (see
            // db/migrations/20260808120000_create_initial_schema.php) is
            // silently inert under sqlite, i.e. in local dev and the whole
            // PHPUnit suite (architecture.md's Testing Strategy runs unit
            // tests against sqlite). MySQL has no equivalent per-connection
            // toggle -- FKs are enforced there by default.
            $pdo->exec('PRAGMA foreign_keys = ON');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $db['host'],
                $db['port'],
                $db['database'],
            );
            $pdo = new PDO($dsn, $db['username'], $db['password']);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    },

    // MysqlFulltextSearch in production (FULLTEXT INDEX, mysql-only -- see
    // db/migrations/20260808120000_create_initial_schema.php); LikeSearch
    // everywhere else (local/test SQLite has no FULLTEXT equivalent -- see
    // architecture.md -- Search / Testing Strategy, and src/Search/LikeSearch.php
    // for why that fallback exists at all). Mirrors the driver branch on
    // PDO::class above.
    RecipeSearch::class => function (ContainerInterface $c): RecipeSearch {
        $db = $c->get('settings')['db'];
        $pdo = $c->get(PDO::class);

        return $db['driver'] === 'mysql' ? new MysqlFulltextSearch($pdo) : new LikeSearch($pdo);
    },

    // No transactional email provider is chosen yet (see architecture.md --
    // Open Questions) -- LogMailer is a working stand-in behind the Mailer
    // interface so the password-reset flow doesn't have to wait on that
    // decision. Swap this binding for a real implementation later.
    Mailer::class => function () use ($root): Mailer {
        return new LogMailer($root . '/storage/mail');
    },

    // Used by App\Scraping\RecipeImporter (the shared pipeline behind both
    // `recipe:import` on the CLI and the admin web UI's "Import a recipe"
    // form) -- the robots.txt check and the single page fetch share one
    // client so the identifying User-Agent and default timeout only need to
    // be set once. Bound to the interface, not Client directly, so tests
    // can substitute a client built around GuzzleHttp\Handler\MockHandler.
    ClientInterface::class => function (): ClientInterface {
        $userAgent = ($_ENV['SCRAPER_USER_AGENT'] ?? '') !== ''
            ? $_ENV['SCRAPER_USER_AGENT']
            : 'fantasy.recipes-scraper/1.0 (one-time personal-project recipe fact scrape)';

        return new Client([
            'headers' => ['User-Agent' => $userAgent],
            'timeout' => 15.0,
            'http_errors' => false,
        ]);
    },

    Twig::class => function (ContainerInterface $c) use ($root): Twig {
        $debug = $c->get('settings')['app_debug'];

        return Twig::create($root . '/templates', [
            'cache' => $debug ? false : $root . '/storage/cache/twig',
            'debug' => $debug,
        ]);
    },
]);

return $builder->build();
