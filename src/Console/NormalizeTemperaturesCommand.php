<?php

declare(strict_types=1);

namespace App\Console;

use App\Recipe\TemperatureNormalizer;
use App\Repository\RecipeRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Batch job: rewrites every recipes row's OriginalInstructions and (where
 * present) NarratorRecipe so every temperature mention reads "Celsius
 * first, Fahrenheit in brackets" -- see App\Recipe\TemperatureNormalizer
 * for the conversion rules and its own extensive docblock for the design
 * rationale.
 *
 * Both fields are in scope: OriginalInstructions is shown to guests too
 * (behind the "Reveal the mundane truth" toggle on
 * templates/recipes/detail.twig), not just NarratorRecipe, so
 * "consistency" means both. OriginalIngredients is deliberately NOT
 * touched -- ingredient lists essentially never carry oven/cooking
 * temperatures, only quantities, and TemperatureNormalizer's job is
 * specifically temperature mentions.
 *
 * Walked in fixed-size pages (an id-cursor, not OFFSET -- see
 * RecipeRepository::listBatchAfterId()) so it's safely interruptible and
 * re-runnable; a write only happens when normalize() actually changed the
 * text, so a re-run after a kill just re-scans already-normalized rows as
 * no-ops rather than re-converting them.
 *
 * --dry-run prints what would change (row id, field, and a short excerpt
 * around the first difference) without writing anything, for a spot-check
 * pass before committing to the real database -- the practice already
 * established for every other bulk-data change in this project.
 */
#[AsCommand(
    name: 'recipe:normalize-temperatures',
    description: 'Rewrite every OriginalInstructions/NarratorRecipe temperature mention to "Celsius (Fahrenheit)" order.',
)]
final class NormalizeTemperaturesCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly RecipeRepository $recipes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing to the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        $scanned = 0;
        $instructionsChanged = 0;
        $narratorRecipeChanged = 0;
        $afterId = 0;

        while (true) {
            $batch = $this->recipes->listBatchAfterId($afterId, self::BATCH_SIZE);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                $afterId = $row['id'];
                $scanned++;

                $originalInstructions = $row['original_instructions'];
                if (TemperatureNormalizer::hasNormalizableTemperature($originalInstructions)) {
                    $normalized = TemperatureNormalizer::normalize($originalInstructions);
                    if ($normalized !== $originalInstructions) {
                        $instructionsChanged++;
                        $this->reportChange($output, $row['id'], 'OriginalInstructions', $originalInstructions, $normalized, $dryRun);
                        if (!$dryRun) {
                            $this->recipes->updateOriginalInstructions($row['id'], $normalized);
                        }
                    }
                }

                $narratorRecipe = $row['narrator_recipe'];
                if ($narratorRecipe !== null && $narratorRecipe !== '' && TemperatureNormalizer::hasNormalizableTemperature($narratorRecipe)) {
                    $normalized = TemperatureNormalizer::normalize($narratorRecipe);
                    if ($normalized !== $narratorRecipe) {
                        $narratorRecipeChanged++;
                        $this->reportChange($output, $row['id'], 'NarratorRecipe', $narratorRecipe, $normalized, $dryRun);
                        if (!$dryRun) {
                            $this->recipes->updateNarratorRecipe($row['id'], (string) $row['narrator'], $normalized);
                        }
                    }
                }
            }

            if ($scanned % 5000 === 0) {
                $output->writeln("<info>{$scanned} scanned so far...</info>");
            }
        }

        $verb = $dryRun ? 'Would change' : 'Changed';
        $output->writeln(
            "<info>Done. Scanned {$scanned} recipe(s). "
            . "{$verb} OriginalInstructions on {$instructionsChanged}; NarratorRecipe on {$narratorRecipeChanged}.</info>",
        );

        return Command::SUCCESS;
    }

    private function reportChange(
        OutputInterface $output,
        int $id,
        string $field,
        string $before,
        string $after,
        bool $dryRun,
    ): void {
        if (!$output->isVerbose() && !$dryRun) {
            return;
        }

        $excerpt = self::firstDifferenceExcerpt($before, $after);
        $output->writeln("#{$id} {$field}: {$excerpt}");
    }

    /**
     * A short "...before...=>...after..." snippet centred on the first
     * point of difference, so a human skimming --dry-run output (or -v
     * output on a live run) can spot-check thousands of rows without
     * having whole recipes dumped to the terminal.
     */
    private static function firstDifferenceExcerpt(string $before, string $after): string
    {
        $length = min(mb_strlen($before), mb_strlen($after));
        $firstDiff = 0;
        while ($firstDiff < $length && mb_substr($before, $firstDiff, 1) === mb_substr($after, $firstDiff, 1)) {
            $firstDiff++;
        }

        $start = max(0, $firstDiff - 30);
        $beforeExcerpt = trim(mb_substr($before, $start, 80));
        $afterExcerpt = trim(mb_substr($after, $start, 80));

        return "...{$beforeExcerpt}... => ...{$afterExcerpt}...";
    }
}
