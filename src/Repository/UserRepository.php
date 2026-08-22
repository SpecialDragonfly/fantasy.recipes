<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * @phpstan-type UserRow array{id: int, username: string, email: string, password_hash: string, role: string, created_at: string}
 */
final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return UserRow|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $id]);

        /** @var UserRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return UserRow|null
     */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE username = :username');
        $statement->execute(['username' => $username]);

        /** @var UserRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return UserRow|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        /** @var UserRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Registration is open self-serve (spec.md -- Roles & Permissions) --
     * every new account gets the 'user' role via the column default.
     */
    public function create(string $username, string $email, string $password): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (username, email, password_hash, created_at) '
            . 'VALUES (:username, :email, :password_hash, :created_at)',
        );

        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param UserRow $user
     */
    public function verifyPassword(array $user, string $password): bool
    {
        return password_verify($password, $user['password_hash']);
    }

    public function updatePassword(int $userId, string $newPassword): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $statement->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
    }
}
