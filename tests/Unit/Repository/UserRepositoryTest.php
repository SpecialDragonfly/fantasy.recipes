<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repository = new UserRepository($this->pdo);
    }

    public function testCreateDefaultsToUserRoleAndHashesPassword(): void
    {
        $id = $this->repository->create('alice', 'alice@example.com', 'correcthorse123');

        $user = $this->repository->findById($id);

        self::assertNotNull($user);
        self::assertSame('alice', $user['username']);
        self::assertSame('alice@example.com', $user['email']);
        self::assertSame('user', $user['role']);
        self::assertNotSame('correcthorse123', $user['password_hash']);
        self::assertTrue(password_verify('correcthorse123', $user['password_hash']));
    }

    public function testFindByUsernameAndEmailAreCaseSensitiveExactMatches(): void
    {
        $this->repository->create('alice', 'alice@example.com', 'password123');

        self::assertNotNull($this->repository->findByUsername('alice'));
        self::assertNull($this->repository->findByUsername('Alice'));
        self::assertNotNull($this->repository->findByEmail('alice@example.com'));
        self::assertNull($this->repository->findByEmail('nobody@example.com'));
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        self::assertNull($this->repository->findById(999));
    }

    public function testVerifyPasswordAcceptsCorrectRejectsWrong(): void
    {
        $id = $this->repository->create('bob', 'bob@example.com', 'correcthorse123');
        $user = $this->repository->findById($id);
        self::assertNotNull($user);

        self::assertTrue($this->repository->verifyPassword($user, 'correcthorse123'));
        self::assertFalse($this->repository->verifyPassword($user, 'wrongpassword'));
    }

    public function testUpdatePasswordChangesWhichPasswordVerifies(): void
    {
        $id = $this->repository->create('carol', 'carol@example.com', 'oldpassword1');

        $this->repository->updatePassword($id, 'newpassword2');

        $user = $this->repository->findById($id);
        self::assertNotNull($user);
        self::assertFalse($this->repository->verifyPassword($user, 'oldpassword1'));
        self::assertTrue($this->repository->verifyPassword($user, 'newpassword2'));
    }

    public function testUsernameUniquenessIsEnforcedAtTheDatabaseLevel(): void
    {
        $this->repository->create('dave', 'dave1@example.com', 'password123');

        $this->expectException(\PDOException::class);
        $this->repository->create('dave', 'dave2@example.com', 'password123');
    }

    public function testEmailUniquenessIsEnforcedAtTheDatabaseLevel(): void
    {
        $this->repository->create('erin', 'erin@example.com', 'password123');

        $this->expectException(\PDOException::class);
        $this->repository->create('erin2', 'erin@example.com', 'password123');
    }

    public function testCreateDefaultsToNoMarketingConsentButAlwaysGetsAnUnsubscribeToken(): void
    {
        $user = $this->repository->findById(
            $this->repository->create('frank', 'frank@example.com', 'password123'),
        );

        self::assertNotNull($user);
        self::assertSame(0, (int) $user['marketing_opt_in']);
        self::assertNull($user['marketing_opt_in_at']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $user['unsubscribe_token']);
    }

    public function testCreateWithMarketingOptInSetsTheFlagAndStampsTheConsentTime(): void
    {
        $user = $this->repository->findById(
            $this->repository->create('grace', 'grace@example.com', 'password123', true),
        );

        self::assertNotNull($user);
        self::assertSame(1, (int) $user['marketing_opt_in']);
        self::assertNotNull($user['marketing_opt_in_at']);
    }

    public function testSetMarketingOptInTogglesFlagAndTimestampBothWays(): void
    {
        $id = $this->repository->create('heidi', 'heidi@example.com', 'password123');

        $this->repository->setMarketingOptIn($id, true);
        $user = $this->repository->findById($id);
        self::assertNotNull($user);
        self::assertSame(1, (int) $user['marketing_opt_in']);
        self::assertNotNull($user['marketing_opt_in_at']);

        $this->repository->setMarketingOptIn($id, false);
        $user = $this->repository->findById($id);
        self::assertNotNull($user);
        self::assertSame(0, (int) $user['marketing_opt_in']);
        self::assertNull($user['marketing_opt_in_at']);
    }

    public function testFindByUnsubscribeTokenRoundTrips(): void
    {
        $id = $this->repository->create('ivan', 'ivan@example.com', 'password123');
        $created = $this->repository->findById($id);
        self::assertNotNull($created);
        $token = (string) $created['unsubscribe_token'];

        $found = $this->repository->findByUnsubscribeToken($token);
        self::assertNotNull($found);
        self::assertSame($id, (int) $found['id']);
        self::assertNull($this->repository->findByUnsubscribeToken('deadbeef'));
    }

    public function testAllReturnsEveryAccountNewestSignupFirst(): void
    {
        $this->pdo->exec("INSERT INTO users (username, email, password_hash, created_at) VALUES ('old', 'old@example.com', 'x', '2026-01-01 09:00:00')");
        $this->pdo->exec("INSERT INTO users (username, email, password_hash, created_at) VALUES ('new', 'new@example.com', 'x', '2026-08-01 09:00:00')");

        $usernames = array_column($this->repository->all(), 'username');

        self::assertSame(['new', 'old'], $usernames);
    }

    public function testAllRowsCarryMarketingOptInStateForTheAdminUsersPage(): void
    {
        $this->repository->create('optedin', 'optedin@example.com', 'password123', true);
        $this->repository->create('optedout', 'optedout@example.com', 'password123', false);

        $rows = [];
        foreach ($this->repository->all() as $row) {
            $rows[$row['username']] = $row;
        }

        self::assertArrayHasKey('marketing_opt_in', $rows['optedin']);
        self::assertSame(1, (int) $rows['optedin']['marketing_opt_in']);
        self::assertNotNull($rows['optedin']['marketing_opt_in_at']);

        self::assertArrayHasKey('marketing_opt_in', $rows['optedout']);
        self::assertSame(0, (int) $rows['optedout']['marketing_opt_in']);
        self::assertNull($rows['optedout']['marketing_opt_in_at']);
    }

    public function testTouchLastLoginStampsTheTimeOnlyForThatUser(): void
    {
        $a = $this->repository->create('a', 'a@example.com', 'password123');
        $b = $this->repository->create('b', 'b@example.com', 'password123');

        $before = $this->repository->findById($a);
        self::assertNotNull($before);
        self::assertNull($before['last_login_at']);

        $this->repository->touchLastLogin($a);

        $after = $this->repository->findById($a);
        self::assertNotNull($after);
        self::assertNotNull($after['last_login_at']);

        $untouched = $this->repository->findById($b);
        self::assertNotNull($untouched);
        self::assertNull($untouched['last_login_at']);
    }

    public function testDeleteRemovesTheUserAndCascadesTheirRows(): void
    {
        $id = $this->repository->create('doomed', 'doomed@example.com', 'password123');
        $this->pdo->exec(sprintf(
            "INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at) VALUES (%d, 'h', '2099-01-01 00:00:00', '2026-01-01 00:00:00')",
            $id,
        ));

        $this->repository->delete($id);

        self::assertNull($this->repository->findById($id));

        $count = $this->pdo->query(
            sprintf('SELECT COUNT(*) FROM password_reset_tokens WHERE user_id = %d', $id),
        );
        self::assertNotFalse($count);
        self::assertSame(0, (int) $count->fetchColumn());
    }
}
