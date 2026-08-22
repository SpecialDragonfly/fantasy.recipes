<?php

declare(strict_types=1);

namespace App\Repository;

use DateInterval;
use DateTimeImmutable;
use PDO;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * Backs the password-reset flow (architecture.md -- Mail): only a hash of
 * the token is ever stored, never the raw value -- the raw token exists
 * only in the emailed link and the requesting browser's form submission.
 *
 * @phpstan-type PasswordResetTokenRow array{id: int, user_id: int, token_hash: string, expires_at: string, created_at: string}
 */
final class PasswordResetTokenRepository
{
    private const TOKEN_TTL = 'PT1H';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Invalidates any prior unused tokens for this user (architecture.md:
     * "expire any unused prior tokens for that user on request"), then
     * issues a new one. Returns the raw token -- only its hash is persisted.
     */
    public function createForUser(int $userId): string
    {
        $this->deleteForUser($userId);

        $token = bin2hex(random_bytes(32));
        $now = new DateTimeImmutable();

        $statement = $this->pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) '
            . 'VALUES (:user_id, :token_hash, :expires_at, :created_at)',
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $this->hash($token),
            'expires_at' => $now->add(new DateInterval(self::TOKEN_TTL))->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Looks up a raw token (as received from the reset link/form) and
     * returns the owning user_id if it exists and has not expired.
     */
    public function findValidUserId(string $rawToken): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT user_id, expires_at FROM password_reset_tokens WHERE token_hash = :token_hash',
        );
        $statement->execute(['token_hash' => $this->hash($rawToken)]);

        /** @var array{user_id: int, expires_at: string}|false $row */
        $row = $statement->fetch();

        if ($row === false) {
            return null;
        }

        $expiresAt = new DateTimeImmutable($row['expires_at']);
        if ($expiresAt < new DateTimeImmutable()) {
            return null;
        }

        return (int) $row['user_id'];
    }

    public function deleteByToken(string $rawToken): void
    {
        $statement = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $this->hash($rawToken)]);
    }

    public function deleteForUser(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
    }

    private function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
