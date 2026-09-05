<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\PasswordChangeValidator;
use PHPUnit\Framework\TestCase;

final class PasswordChangeValidatorTest extends TestCase
{
    public function testReturnsNullWhenEverythingIsValid(): void
    {
        self::assertNull(
            PasswordChangeValidator::firstError(true, 'newpassword2', 'newpassword2'),
        );
    }

    public function testWrongCurrentPasswordIsReportedFirst(): void
    {
        // Even with an otherwise-broken new password, the current-password
        // failure takes precedence.
        self::assertSame(
            'Your current password is incorrect.',
            PasswordChangeValidator::firstError(false, 'short', 'mismatch'),
        );
    }

    public function testShortNewPasswordIsRejectedOnceCurrentPasswordIsOk(): void
    {
        self::assertSame(
            'Password must be at least 8 characters.',
            PasswordChangeValidator::firstError(true, 'short', 'short'),
        );
    }

    public function testSevenCharacterPasswordIsTooShortButEightIsFine(): void
    {
        self::assertSame(
            'Password must be at least 8 characters.',
            PasswordChangeValidator::firstError(true, '1234567', '1234567'),
        );
        self::assertNull(
            PasswordChangeValidator::firstError(true, '12345678', '12345678'),
        );
    }

    public function testMismatchedConfirmationIsRejectedLast(): void
    {
        self::assertSame(
            'New passwords do not match.',
            PasswordChangeValidator::firstError(true, 'longenough1', 'longenough2'),
        );
    }
}
