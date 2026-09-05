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
 * Admin impersonation ("log in as another user") stashes the real admin's
 * record under $_SESSION['impersonator'] (same shape as $_SESSION['user'])
 * while $_SESSION['user'] holds the target. stopImpersonating() swaps it
 * back; logout()'s `$_SESSION = []` drops both keys together.
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

    /**
     * Begin acting as another user. The current $_SESSION['user'] (the real
     * admin) is stashed under $_SESSION['impersonator'] so stopImpersonating()
     * can restore it. No-op if nobody is logged in.
     *
     * The id is regenerated on this privilege change (session fixation), then
     * BOTH keys are (re)written -- session_regenerate_id() keeps the in-memory
     * $_SESSION, and writing afterwards is what guarantees both land in the
     * new session file.
     *
     * @param array{id: int, username: string, role: string} $targetUser
     */
    public static function startImpersonating(array $targetUser): void
    {
        $original = self::user();

        if ($original === null) {
            return;
        }

        session_regenerate_id(true);

        $_SESSION['impersonator'] = $original;
        $_SESSION['user'] = [
            'id' => (int) $targetUser['id'],
            'username' => $targetUser['username'],
            'role' => $targetUser['role'],
        ];
    }

    /**
     * Restore the real admin session stashed by startImpersonating() and drop
     * the impersonator key. Harmless no-op when not impersonating.
     */
    public static function stopImpersonating(): void
    {
        $original = self::impersonator();

        if ($original === null) {
            return;
        }

        session_regenerate_id(true);

        $_SESSION['user'] = $original;
        unset($_SESSION['impersonator']);
    }

    public static function isImpersonating(): bool
    {
        return self::impersonator() !== null;
    }

    /**
     * The stashed original admin record while impersonating, or null.
     *
     * @return array{id: int, username: string, role: string}|null
     */
    public static function impersonator(): ?array
    {
        /** @var array{id: int, username: string, role: string}|null $impersonator */
        $impersonator = $_SESSION['impersonator'] ?? null;

        return $impersonator;
    }
}
