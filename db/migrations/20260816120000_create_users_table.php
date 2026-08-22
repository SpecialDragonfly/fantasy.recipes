<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * One migration per table (see architecture.md -- Data Model Notes) --
 * this project has nothing deployed yet, so the whole migration history was
 * squashed to a fresh baseline rather than layering more ALTER TABLEs on
 * the old recipe-import shape. No ENUM columns, same reasoning as always:
 * VARCHAR + application-level validation, so the same migration runs on
 * both the mysql (prod) and sqlite (local/test) Phinx adapters.
 */
final class CreateUsersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users', ['id' => 'id'])
            ->addColumn('username', 'string', ['limit' => 64])
            ->addColumn('email', 'string', ['limit' => 255])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            // 'user' | 'admin' -- flat two-tier account model; Guest is the
            // absence of a session, not a row. See spec.md -- Roles.
            ->addColumn('role', 'string', ['limit' => 16, 'default' => 'user'])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['username'], ['unique' => true])
            ->addIndex(['email'], ['unique' => true])
            ->create();
    }
}
