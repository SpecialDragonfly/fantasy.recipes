<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Flat three-tier model from spec.md -- Roles & Permissions. Guest is the
 * absence of a session, not a stored value anywhere (see the `users.role`
 * comment in the initial migration) -- it only exists here as the bottom of
 * the ordering used by RequireRoleMiddleware.
 */
final class Roles
{
    public const GUEST = 'guest';
    public const USER = 'user';
    public const ADMIN = 'admin';

    private const RANK = [
        self::GUEST => 0,
        self::USER => 1,
        self::ADMIN => 2,
    ];

    public static function atLeast(?string $role, string $minimum): bool
    {
        $roleRank = self::RANK[$role ?? self::GUEST] ?? 0;
        $minimumRank = self::RANK[$minimum] ?? 0;

        return $roleRank >= $minimumRank;
    }
}
