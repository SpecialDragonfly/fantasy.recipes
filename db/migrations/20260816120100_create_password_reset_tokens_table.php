<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * See architecture.md -- Application Architecture (Mail): backs the
 * password-reset flow, the one place this app sends email.
 */
final class CreatePasswordResetTokensTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('password_reset_tokens', ['id' => 'id'])
            // signed => false to match Phinx's unsigned default `id` PK on
            // MySQL -- an INT vs INT UNSIGNED mismatch makes addForeignKey
            // fail with errno 150. SQLite ignores signedness, so the local
            // test suite never surfaced this.
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('token_hash', 'string', ['limit' => 255])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('created_at', 'datetime')
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
