<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

/**
 * The login event log -- one row per successful sign-in (see
 * db/migrations/20260831180000_create_login_events_table.php). Written from
 * the login route; read by the admin metrics page. In-house only, never
 * sent anywhere.
 */
final class LoginEventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record one successful sign-in. Called from src/Routes/auth.php next
     * to UserRepository::touchLastLogin().
     */
    public function record(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_events (user_id, logged_in_at) VALUES (:user_id, :at)',
        );
        $statement->execute([
            'user_id' => $userId,
            'at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Per-day login totals for the last $days days (today inclusive),
     * most recent day first. Days with no logins are simply absent.
     *
     * DATE() is understood by both the mysql and sqlite adapters.
     *
     * @return list<array{day: string, distinct_users: int, total_logins: int}>
     */
    public function dailyCounts(int $days = 90): array
    {
        $since = (new DateTimeImmutable(sprintf('-%d days', max(0, $days - 1))))->format('Y-m-d 00:00:00');

        $statement = $this->pdo->prepare(
            'SELECT DATE(logged_in_at) AS day, '
            . 'COUNT(DISTINCT user_id) AS distinct_users, '
            . 'COUNT(*) AS total_logins '
            . 'FROM login_events '
            . 'WHERE logged_in_at >= :since '
            . 'GROUP BY DATE(logged_in_at) '
            . 'ORDER BY day DESC',
        );
        $statement->execute(['since' => $since]);

        $rows = [];
        /** @var array{day: string, distinct_users: int|string, total_logins: int|string} $row */
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'day' => (string) $row['day'],
                'distinct_users' => (int) $row['distinct_users'],
                'total_logins' => (int) $row['total_logins'],
            ];
        }

        return $rows;
    }
}
