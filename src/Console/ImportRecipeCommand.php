<?php

declare(strict_types=1);

namespace App\Console;

use App\Scraping\RecipeImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Thin CLI wrapper around RecipeImporter -- see that class for the actual
 * fetch/extract/validate pipeline, which this shares with the admin web
 * UI's "Import a recipe" form (src/Routes/admin.php's /admin/recipes/import
 * routes). Useful for scripting/batch runs from the server directly; the
 * web form is the easier path for a one-off import.
 */
#[AsCommand(
    name: 'recipe:import',
    description: 'Import a single recipe by URL into recipes at status=draft.',
)]
final class ImportRecipeCommand extends Command
{
    public function __construct(private readonly RecipeImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'The recipe page to import.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Import even if a recipe with this exact origin has already been imported.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $url */
        $url = $input->getArgument('url');
        $force = (bool) $input->getOption('force');

        $result = $this->importer->import($url, $force);

        if (!$result->success) {
            $io->error((string) $result->error);

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Imported "%s" as recipe #%d (%s), status: draft.',
            $result->title,
            $result->recipeId,
            $result->slug,
        ));

        return Command::SUCCESS;
    }
}
