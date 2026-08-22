<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Roles;
use App\Auth\SessionAuth;
use PHPUnit\Framework\TestCase;

/**
 * SessionAuth deliberately wraps $_SESSION directly rather than going
 * through DI (see its own docblock) -- that global state is exercised here
 * directly rather than built around with an artificial seam. login()/
 * logout() call session_regenerate_id(true), which requires an active
 * session; PHPUnit's CLI SAPI allows starting one without a real HTTP
 * request.
 */
final class SessionAuthTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testStartsLoggedOut(): void
    {
        self::assertFalse(SessionAuth::isLoggedIn());
        self::assertNull(SessionAuth::user());
        self::assertNull(SessionAuth::id());
        self::assertSame(Roles::GUEST, SessionAuth::role());
        self::assertFalse(SessionAuth::isAdmin());
    }

    public function testLoginPopulatesSessionUser(): void
    {
        SessionAuth::login(['id' => 42, 'username' => 'alice', 'role' => Roles::USER]);

        self::assertTrue(SessionAuth::isLoggedIn());
        self::assertSame(42, SessionAuth::id());
        self::assertSame(Roles::USER, SessionAuth::role());
        self::assertFalse(SessionAuth::isAdmin());
        self::assertSame(['id' => 42, 'username' => 'alice', 'role' => Roles::USER], SessionAuth::user());
    }

    public function testIsAdminReflectsRole(): void
    {
        SessionAuth::login(['id' => 1, 'username' => 'root', 'role' => Roles::ADMIN]);

        self::assertTrue(SessionAuth::isAdmin());
    }

    public function testLogoutClearsTheSession(): void
    {
        SessionAuth::login(['id' => 42, 'username' => 'alice', 'role' => Roles::USER]);

        SessionAuth::logout();

        self::assertFalse(SessionAuth::isLoggedIn());
        self::assertNull(SessionAuth::user());
        self::assertSame(Roles::GUEST, SessionAuth::role());
    }
}
