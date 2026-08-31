<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Records when each account last authenticated -- shown on the admin users
 * page (templates/admin/users.twig). Stamped by UserRepository::touchLastLogin()
 * from the login route on every successful sign-in (password login and the
 * post-password-reset auto-login).
 *
 * Nullable with no backfill: there's no login history to reconstruct, so
 * existing accounts read as "never" until their next sign-in.
 */
final class AddLastLoginAtToUsers extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users')
            ->addColumn('last_login_at', 'datetime', ['null' => true])
            ->update();
    }
}
