<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Record who made each recipe view: the (truncated) User-Agent and an
 * is_bot flag (App\Http\BotDetector), so human and automated views can be
 * told apart after the fact without depending on rotating nginx logs.
 *
 * From this migration on, bot views ARE recorded (flagged is_bot = 1)
 * rather than skipped; the metrics queries exclude them. Rows written
 * before this migration have no User-Agent and default to is_bot = 0, so
 * they remain indistinguishable from each other.
 */
final class AddUserAgentAndIsBotToRecipeViews extends AbstractMigration
{
    public function change(): void
    {
        $this->table('recipe_views')
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('is_bot', 'boolean', ['default' => false])
            ->addIndex(['is_bot'])
            ->update();
    }
}
