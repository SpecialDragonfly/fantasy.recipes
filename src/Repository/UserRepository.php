<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * Thin PDO repository, no ORM -- see architecture.md -- Application
 * Architecture (Data layer).
 *
 * @phpstan-type UserRow array{id: int, username: string, email: string, password_hash: string, role: string, created_at: string, last_login_at: string|null, marketing_opt_in: int, marketing_opt_in_at: string|null, unsubscribe_token: string|null}
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
     * Every account, newest signup first -- the admin users page
     * (templates/admin/users.twig).
     *
     * @return list<UserRow>
     */
    public function all(): array
    {
        $statement = $this->pdo->query('SELECT * FROM users ORDER BY created_at DESC, id DESC');

        /** @var list<UserRow> */
        return $statement === false ? [] : $statement->fetchAll();
    }

    /**
     * Everyone currently opted in to the "new recipe" emails. Snapshotted
     * into a campaign's delivery rows at enqueue time (see
     * RecipeEmailQueueRepository::createCampaign).
     *
     * @return list<UserRow>
     */
    public function listMarketingOptedIn(): array
    {
        $statement = $this->pdo->query('SELECT * FROM users WHERE marketing_opt_in = 1 ORDER BY id ASC');

        /** @var list<UserRow> */
        return $statement === false ? [] : $statement->fetchAll();
    }

    /**
     * @return UserRow|null
     */
    public function findByUnsubscribeToken(string $token): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE unsubscribe_token = :token');
        $statement->execute(['token' => $token]);

        /** @var UserRow|false $row */
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Registration is open self-serve (spec.md -- Roles & Permissions) --
     * every new account gets the 'user' role via the column default.
     *
     * `$marketingOptIn` is the "email me about new recipes" box on the
     * register form (unticked by default). When true, `marketing_opt_in_at`
     * records the moment of consent so it can be demonstrated later. Every
     * account gets an `unsubscribe_token` regardless, for the no-login
     * opt-out link in the eventual marketing emails.
     */
    public function create(string $username, string $email, string $password, bool $marketingOptIn = false): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO users '
            . '(username, email, password_hash, created_at, marketing_opt_in, marketing_opt_in_at, unsubscribe_token) '
            . 'VALUES (:username, :email, :password_hash, :created_at, :opt_in, :opt_in_at, :unsub_token)',
        );

        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
            'opt_in' => $marketingOptIn ? 1 : 0,
            'opt_in_at' => $marketingOptIn ? $now : null,
            'unsub_token' => bin2hex(random_bytes(16)),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Toggle the marketing-email consent flag (from the account page, or an
     * unsubscribe link). Opting in stamps `marketing_opt_in_at` with the
     * consent time; opting out clears it.
     */
    public function setMarketingOptIn(int $userId, bool $optIn): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET marketing_opt_in = :opt_in, marketing_opt_in_at = :opt_in_at WHERE id = :id',
        );

        $statement->execute([
            'opt_in' => $optIn ? 1 : 0,
            'opt_in_at' => $optIn ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
            'id' => $userId,
        ]);
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

    /**
     * Stamp the last-authenticated time. Called from the login route on
     * every successful sign-in (see src/Routes/auth.php).
     */
    public function touchLastLogin(int $userId): void
    {
        $statement = $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $statement->execute([
            'now' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }

    /**
     * Hard delete, from the admin users page. The user's own rows go with
     * them via ON DELETE CASCADE (password_reset_tokens, grimoire_entries,
     * personal_recipes, recipe_email_queue_deliveries); any Story they
     * authored survives with author_user_id set to NULL (see the
     * migrations).
     */
    public function delete(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }
}
