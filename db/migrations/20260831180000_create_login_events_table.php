<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One row per successful sign-in -- the raw event log behind the in-house
 * login metrics (admin at /admin/metrics; App\Repository\LoginEventRepository).
 * Written from the login route alongside users.last_login_at.
 *
 * Kept as raw events rather than a pre-aggregated daily table so other
 * cuts (weekly actives, per-user) stay possible later, and so the write is
 * a plain portable INSERT rather than a MySQL/SQLite-divergent upsert.
 *
 * FK is ON DELETE CASCADE, consistent with the rest of the schema: deleting
 * a user from /admin/users takes their login history with them (which does
 * retroactively lower the distinct-user count for past days -- acceptable
 * for a personal site, noted in architecture.md).
 */
final class CreateLoginEventsTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('login_events')
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('logged_in_at', 'datetime')
            ->addIndex(['logged_in_at'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
