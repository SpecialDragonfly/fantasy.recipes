<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Thin wrapper around the native PHP session for the logged-in-user record.
 * Deliberately not a service pulled from the DI container -- $_SESSION is
 * already process-global state by nature, so wrapping it in an injectable
 * class would just be ceremony. See architecture.md -- Application
 * Architecture (Sessions).
 *
 * @phpstan-type SessionUser array{id: int, username: string, role: string}
 */
final class SessionAuth
{
    /**
     * @param array{id: int, username: string, role: string} $user
     */
    public static function login(array $user): void
    {
        // Regenerate the session id on privilege change (login) to prevent
        // session fixation.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
        ];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * @return array{id: int, username: string, role: string}|null
     */
    public static function user(): ?array
    {
        /** @var array{id: int, username: string, role: string}|null $user */
        $user = $_SESSION['user'] ?? null;

        return $user;
    }

    public static function id(): ?int
    {
        return self::user()['id'] ?? null;
    }

    public static function role(): string
    {
        return self::user()['role'] ?? Roles::GUEST;
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return self::role() === Roles::ADMIN;
    }
}
