<?php

declare(strict_types=1);

namespace App\Scraping;

/**
 * Raw facts extracted from one source page -- never published as-is, an
 * admin rewrites these into original prose (spec.md -- Content Pipeline).
 */
final class ScrapedRecipeData
{
    public function __construct(
        public readonly ?string $title,
        /** Newline-joined ingredient lines. */
        public readonly ?string $ingredientsRaw,
        /** Newline-joined instruction steps. */
        public readonly ?string $instructionsRaw,
        public readonly ?string $imageUrl,
    ) {
    }
}
