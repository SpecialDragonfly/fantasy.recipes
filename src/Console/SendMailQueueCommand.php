<?php

declare(strict_types=1);

namespace App\Console;

use App\Mail\RecipeNotifications;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Daily cron (shortly after mail:enqueue-recipe-notifications). Sends every
 * campaign whose scheduled time has passed. A delivery error stops the
 * campaign and marks it `failed` -- run again, or use the admin "Retry"
 * button, to resume from where it stopped (see
 * App\Mail\RecipeNotifications::sendCampaign).
 */
#[AsCommand(
    name: 'mail:send-queue',
    description: 'Send all due campaigns in the recipe-email queue.',
)]
final class SendMailQueueCommand extends Command
{
    public function __construct(private readonly RecipeNotifications $notifications)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->notifications->sendDue();

        $output->writeln('Queue processed.');

        return Command::SUCCESS;
    }
}
