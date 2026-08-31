<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Auth\Roles;
use App\Auth\SessionAuth;
use App\Http\Middleware\RequireRoleMiddleware;
use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Session handling exercised directly via $_SESSION, same reasoning as
 * SessionAuthTest.
 */
final class RequireRoleMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->pdo = InMemoryDatabase::create();
        $this->users = new UserRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function passThroughHandler(): Handler
    {
        return new class implements Handler {
            public function handle(Request $request): Response
            {
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    public function testLoggedOutIsRedirectedToLogin(): void
    {
        $middleware = new RequireRoleMiddleware(Roles::USER, $this->users);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/grimoire');

        $response = $middleware->process($request, $this->passThroughHandler());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?next=%2Fgrimoire', $response->getHeaderLine('Location'));
    }

    public function testSufficientRoleForAnExistingUserPassesThrough(): void
    {
        $userId = $this->users->create('alice', 'alice@example.com', 'password123');
        SessionAuth::login(['id' => $userId, 'username' => 'alice', 'role' => Roles::USER]);

        $middleware = new RequireRoleMiddleware(Roles::USER, $this->users);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/grimoire');

        $response = $middleware->process($request, $this->passThroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue(SessionAuth::isLoggedIn());
    }

    public function testInsufficientRoleForAnExistingUserIsForbidden(): void
    {
        $userId = $this->users->create('alice', 'alice@example.com', 'password123');
        SessionAuth::login(['id' => $userId, 'username' => 'alice', 'role' => Roles::USER]);

        $middleware = new RequireRoleMiddleware(Roles::ADMIN, $this->users);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');

        $response = $middleware->process($request, $this->passThroughHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The bug this guards against: a session logged in as a user id that
     * no longer exists in the database (the account was deleted, or the DB
     * was reset/reseeded, while still logged in). Previously this sailed
     * through as "logged in" all the way to a repository INSERT using that
     * id as a FOREIGN KEY, crashing with a PDOException instead of being
     * caught here and treated as logged-out.
     */
    public function testAStaleSessionForANonexistentUserIsLoggedOutAndRedirectedToLogin(): void
    {
        SessionAuth::login(['id' => 999999, 'username' => 'ghost', 'role' => Roles::USER]);

        $middleware = new RequireRoleMiddleware(Roles::USER, $this->users);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/grimoire');

        $response = $middleware->process($request, $this->passThroughHandler());

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?next=%2Fgrimoire', $response->getHeaderLine('Location'));
        self::assertFalse(SessionAuth::isLoggedIn());
    }

    public function testAStaleAdminSessionIsLoggedOutRatherThanGrantedAccess(): void
    {
        SessionAuth::login(['id' => 999999, 'username' => 'ghost', 'role' => Roles::ADMIN]);

        $middleware = new RequireRoleMiddleware(Roles::ADMIN, $this->users);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');

        $response = $middleware->process($request, $this->passThroughHandler());

        // Logged out entirely (redirected to login), not merely denied --
        // a stale id must never reach a route handler as if it were a real,
        // authenticated user, regardless of what role the stale session
        // claims.
        self::assertSame(302, $response->getStatusCode());
        self::assertFalse(SessionAuth::isLoggedIn());
    }
}
