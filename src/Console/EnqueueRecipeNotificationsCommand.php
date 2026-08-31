<?php

declare(strict_types=1);

namespace App\Console;

use App\Mail\RecipeNotifications;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Daily cron. Rolls every published-but-unannounced recipe into one queued
 * campaign -- `single` for exactly one, `digest` for more (see
 * App\Mail\RecipeNotifications::enqueuePending and
 * db/migrations/20260831150000_create_recipe_email_queue.php). Also exposed
 * as the admin "Check now" button. The campaign is created `pending`;
 * `mail:send-queue` sends it.
 */
#[AsCommand(
    name: 'mail:enqueue-recipe-notifications',
    description: 'Queue a "new recipe(s)" email for recipes published since the last run.',
)]
final class EnqueueRecipeNotificationsCommand extends Command
{
    public function __construct(private readonly RecipeNotifications $notifications)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $campaignId = $this->notifications->enqueuePending();

        $output->writeln(
            $campaignId === null
                ? 'Nothing to announce.'
                : sprintf('Queued campaign #%d.', $campaignId),
        );

        return Command::SUCCESS;
    }
}
