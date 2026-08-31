<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A database-backed queue for the "new recipe(s)" marketing emails.
 *
 *  - `recipes.notified_at` -- NULL on a published recipe means it hasn't
 *    been announced yet. `mail:enqueue-recipe-notifications` (daily cron,
 *    or the admin "Check now" button) sweeps those: exactly one un-announced
 *    recipe makes a `single` campaign, two or more make a `digest`.
 *
 *  - `recipe_email_queue` -- one row per campaign. `status` moves
 *    pending -> sending -> sent, or -> failed (a send error / provider rate
 *    limit -- this is the dead-letter state, resumable via admin "Retry"),
 *    or -> cancelled (admin, before it sends -- which also clears
 *    notified_at on its recipes so a later sweep re-announces them).
 *    `mail:send-queue` (daily cron, or admin "Send now") sends campaigns
 *    whose scheduled_for has passed.
 *
 *  - `recipe_email_queue_recipes` -- which recipes a campaign announces
 *    (for the admin list; FK CASCADE so a deleted recipe drops out).
 *
 *  - `recipe_email_queue_deliveries` -- one row per (campaign, recipient),
 *    snapshotting who was opted in when the campaign was built. The sender
 *    walks the `pending` ones; a mid-send failure leaves the rest pending
 *    so a retry resumes exactly where it stopped.
 *
 * FK columns are `signed => false` to match the unsigned default `id` PKs
 * (an INT vs INT UNSIGNED mismatch fails addForeignKey on MySQL, errno 150).
 */
final class CreateRecipeEmailQueue extends AbstractMigration
{
    public function change(): void
    {
        $this->table('recipes')
            ->addColumn('notified_at', 'datetime', ['null' => true])
            ->update();

        $this->table('recipe_email_queue', ['id' => 'id'])
            ->addColumn('kind', 'string', ['limit' => 16])
            ->addColumn('subject', 'string', ['limit' => 255])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending'])
            ->addColumn('scheduled_for', 'datetime')
            ->addColumn('recipients_total', 'integer', ['default' => 0, 'signed' => false])
            ->addColumn('recipients_sent', 'integer', ['default' => 0, 'signed' => false])
            ->addColumn('last_error', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addIndex(['status', 'scheduled_for'])
            ->create();

        $this->table('recipe_email_queue_recipes', ['id' => false, 'primary_key' => ['queue_id', 'recipe_id']])
            ->addColumn('queue_id', 'integer', ['signed' => false])
            ->addColumn('recipe_id', 'integer', ['signed' => false])
            ->addForeignKey('queue_id', 'recipe_email_queue', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('recipe_id', 'recipes', 'id', ['delete' => 'CASCADE'])
            ->create();

        $this->table('recipe_email_queue_deliveries', ['id' => 'id'])
            ->addColumn('queue_id', 'integer', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false])
            ->addColumn('recipient_email', 'string', ['limit' => 255])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'pending'])
            ->addColumn('error', 'text', ['null' => true])
            ->addColumn('sent_at', 'datetime', ['null' => true])
            ->addIndex(['queue_id', 'user_id'], ['unique' => true])
            ->addIndex(['queue_id', 'status'])
            ->addForeignKey('queue_id', 'recipe_email_queue', 'id', ['delete' => 'CASCADE'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE'])
            ->create();
    }
}
