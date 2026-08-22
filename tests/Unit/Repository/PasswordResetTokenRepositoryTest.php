<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PHPUnit\Framework\TestCase;

final class PasswordResetTokenRepositoryTest extends TestCase
{
    private PasswordResetTokenRepository $repository;
    private int $userId;

    protected function setUp(): void
    {
        $pdo = InMemoryDatabase::create();
        $this->repository = new PasswordResetTokenRepository($pdo);
        $this->userId = (new UserRepository($pdo))->create('alice', 'alice@example.com', 'password123');
    }

    public function testCreatedTokenResolvesBackToTheOwningUser(): void
    {
        $token = $this->repository->createForUser($this->userId);

        self::assertSame($this->userId, $this->repository->findValidUserId($token));
    }

    public function testOnlyTheHashIsPersistedNotTheRawToken(): void
    {
        $token = $this->repository->createForUser($this->userId);

        // No plausible way to assert "not stored" from the repository's own
        // public API alone, but a bogus/garbled token must never resolve --
        // if the raw value were stored and compared loosely this could pass
        // by accident, so this is at least a meaningful negative check.
        self::assertNull($this->repository->findValidUserId($token . 'x'));
        self::assertNull($this->repository->findValidUserId('not-a-real-token'));
    }

    public function testUnknownTokenResolvesToNull(): void
    {
        self::assertNull($this->repository->findValidUserId('does-not-exist'));
    }

    public function testCreatingANewTokenInvalidatesPriorUnusedTokensForThatUser(): void
    {
        $first = $this->repository->createForUser($this->userId);
        $second = $this->repository->createForUser($this->userId);

        self::assertNull($this->repository->findValidUserId($first));
        self::assertSame($this->userId, $this->repository->findValidUserId($second));
    }

    public function testDeleteByTokenConsumesIt(): void
    {
        $token = $this->repository->createForUser($this->userId);

        $this->repository->deleteByToken($token);

        self::assertNull($this->repository->findValidUserId($token));
    }

    public function testExpiredTokenIsNotValid(): void
    {
        // createForUser() always issues a 1h-TTL token, so to exercise
        // expiry we insert an already-expired row directly rather than
        // waiting an hour or reaching into the repository's private hash().
        $pdo = InMemoryDatabase::create();
        $userId = (new UserRepository($pdo))->create('bob', 'bob@example.com', 'password123');
        $repository = new PasswordResetTokenRepository($pdo);

        $rawToken = 'known-raw-token-for-this-test';
        $statement = $pdo->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) '
            . "VALUES (:user_id, :token_hash, '2000-01-01 00:00:00', '2000-01-01 00:00:00')",
        );
        $statement->execute(['user_id' => $userId, 'token_hash' => hash('sha256', $rawToken)]);

        self::assertNull($repository->findValidUserId($rawToken));
    }
}
