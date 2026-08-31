<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Marketing-email consent on the user account.
 *
 *  - `marketing_opt_in`      -- the flag. Default false: existing accounts,
 *    and anyone who doesn't tick the box at registration, are NOT opted in
 *    (UK GDPR / PECR -- consent must be an active opt-in, never assumed).
 *  - `marketing_opt_in_at`   -- when consent was last given, so it can be
 *    demonstrated. Set alongside the flag; nulled when they opt out.
 *  - `unsubscribe_token`     -- stable per-user secret for a no-login
 *    unsubscribe link in the eventual marketing emails (every marketing
 *    email must offer a one-click opt-out). Generated for new users in
 *    UserRepository::create(); backfilled here for existing rows.
 *
 * Consent is first-party only ("email me when a new recipe is published").
 * The data is not shared with or sold to third parties.
 *
 * `boolean` maps to TINYINT(1) on mysql and INTEGER on sqlite -- portable,
 * same as every other column choice in this migration set.
 */
final class AddMarketingOptInToUsers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('users')
            ->addColumn('marketing_opt_in', 'boolean', ['default' => false, 'null' => false])
            ->addColumn('marketing_opt_in_at', 'datetime', ['null' => true])
            ->addColumn('unsubscribe_token', 'string', ['limit' => 32, 'null' => true])
            ->addIndex(['unsubscribe_token'], ['unique' => true])
            ->update();

        foreach ($this->fetchAll('SELECT id FROM users WHERE unsubscribe_token IS NULL') as $row) {
            $this->getAdapter()->execute(sprintf(
                "UPDATE users SET unsubscribe_token = '%s' WHERE id = %d",
                bin2hex(random_bytes(16)),
                (int) $row['id'],
            ));
        }
    }

    public function down(): void
    {
        $this->table('users')
            ->removeIndex(['unsubscribe_token'])
            ->removeColumn('marketing_opt_in')
            ->removeColumn('marketing_opt_in_at')
            ->removeColumn('unsubscribe_token')
            ->update();
    }
}
