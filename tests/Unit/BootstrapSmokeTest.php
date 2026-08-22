<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Confirms the shared container (src/bootstrap.php) actually boots and can
 * open a database connection -- the same bootstrap used by both
 * public/index.php and bin/console. Run against SQLite locally
 * (.env.testing), no MySQL required.
 */
final class BootstrapSmokeTest extends TestCase
{
    public function testContainerBuildsAndConnectsToDatabase(): void
    {
        $_ENV['APP_ENV'] = 'testing';

        $container = require dirname(__DIR__, 2) . '/src/bootstrap.php';

        $pdo = $container->get(PDO::class);

        self::assertInstanceOf(PDO::class, $pdo);
    }
}
