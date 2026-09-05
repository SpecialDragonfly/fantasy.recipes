<?php

declare(strict_types=1);

namespace App\Account;

/**
 * The validation ordering for the self-service "change my password" form
 * (POST /account/password -- see src/Routes/user.php). Pulled out of the
 * route handler so the precedence of the three checks is unit-testable
 * without an HTTP harness.
 *
 * The current-password check is passed in as an already-resolved boolean
 * (the caller runs UserRepository::verifyPassword) so this stays a pure
 * function with no database or hashing dependency.
 */
final class PasswordChangeValidator
{
    /**
     * The first thing wrong with the submission, or null if it's valid.
     * Messages match the wording used elsewhere in this codebase
     * (src/Routes/auth.php, src/Routes/password_reset.php).
     */
    public static function firstError(
        bool $currentPasswordValid,
        string $newPassword,
        string $newPasswordConfirm,
    ): ?string {
        if (!$currentPasswordValid) {
            return 'Your current password is incorrect.';
        }

        if (strlen($newPassword) < 8) {
            return 'Password must be at least 8 characters.';
        }

        if ($newPassword !== $newPasswordConfirm) {
            return 'New passwords do not match.';
        }

        return null;
    }
}
