<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\Roles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function rankProvider(): array
    {
        return [
            'guest meets guest minimum' => [Roles::GUEST, Roles::GUEST, true],
            'user meets guest minimum' => [Roles::USER, Roles::GUEST, true],
            'admin meets guest minimum' => [Roles::ADMIN, Roles::GUEST, true],
            'guest does not meet user minimum' => [Roles::GUEST, Roles::USER, false],
            'user meets user minimum' => [Roles::USER, Roles::USER, true],
            'admin meets user minimum' => [Roles::ADMIN, Roles::USER, true],
            'user does not meet admin minimum' => [Roles::USER, Roles::ADMIN, false],
            'admin meets admin minimum' => [Roles::ADMIN, Roles::ADMIN, true],
        ];
    }

    #[DataProvider('rankProvider')]
    public function testAtLeast(string $role, string $minimum, bool $expected): void
    {
        self::assertSame($expected, Roles::atLeast($role, $minimum));
    }

    public function testNullRoleIsTreatedAsGuest(): void
    {
        self::assertTrue(Roles::atLeast(null, Roles::GUEST));
        self::assertFalse(Roles::atLeast(null, Roles::USER));
        self::assertFalse(Roles::atLeast(null, Roles::ADMIN));
    }
}
