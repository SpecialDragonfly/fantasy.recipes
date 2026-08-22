<?php

declare(strict_types=1);

namespace App\Console;

use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Batch job: calls the Claude API for every recipe still missing a
 * NarratorRecipe, drafting one (and a matching Story via StoryRepository)
 * in the assigned Narrator's voice. Deliberately a batch CLI command
 * rather than a per-recipe web action -- fits "thousands of recipes"
 * better; admin review happens afterward through the normal web UI at
 * status = 'draft'. See spec.md -- Content Pipeline and architecture.md --
 * CLI Commands.
 *
 * Not implemented yet, same as before the recipes-table merge -- still a
 * stub. The old translation_status ('none'/'drafted'/'reviewed') that used
 * to mark which InstructionSet rows still needed this no longer exists;
 * once implemented, this should select recipes where narrator_recipe IS
 * NULL (or, for a re-draft pass, all of them) via RecipeRepository, and use
 * RecipeRepository::updateNarratorRecipe() + StoryRepository::replace() per
 * row -- one row at a time, so the command stays safe to interrupt.
 */
#[AsCommand(
    name: 'recipe:translate-draft',
    description: 'Draft NarratorRecipe + Story via the Claude API for every recipe still missing one.',
)]
final class TranslateDraftCommand extends Command
{
    public function __construct(
        // Not read yet -- execute() is a stub. Will be used once the
        // SELECT/UPDATE loop described below is implemented.
        // @phpstan-ignore property.onlyWritten
        private readonly PDO $pdo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>recipe:translate-draft is not implemented yet.</comment>');

        // TODO:
        //   SELECT id, narrator, original_ingredients, original_instructions
        //   FROM recipes WHERE narrator_recipe IS NULL
        // For each row: call the Claude API (via Guzzle) to draft
        // NarratorRecipe + a Story in that Narrator's voice (see
        // personas.md), then immediately
        //   RecipeRepository::updateNarratorRecipe($id, $narrator, $draft)
        //   StoryRepository::create($id, $narrator, $storyBody, null)
        // -- one row at a time, so the command is safe to interrupt.

        return Command::SUCCESS;
    }
}
