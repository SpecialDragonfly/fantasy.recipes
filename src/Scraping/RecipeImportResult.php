<?php

declare(strict_types=1);

namespace App\Scraping;

/**
 * Outcome of one RecipeImporter::import() call. A value object rather than
 * exceptions, so both the CLI command and the admin web route can render
 * their own success/failure UI straight off the result without a
 * try/catch per failure mode.
 *
 * Failures come in two kinds, distinguished by `extractionFailed`:
 * - "already imported" -- automation itself still works fine, this is
 *   just a dedupe safety check the admin can override with --force/the
 *   web form's "force" checkbox. Retrying the same automated import is the
 *   right next step.
 * - everything else (robots.txt disallowed it, a fetch/HTTP error, no
 *   usable structured recipe data) -- automation is a dead end for this
 *   URL. The admin web route sends these to the manual-entry-with-source-
 *   open-alongside page instead of just showing an error (see
 *   src/Routes/admin.php's /admin/recipes/import/manual route).
 */
final class RecipeImportResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $recipeId,
        public readonly ?string $slug,
        public readonly ?string $title,
        public readonly ?string $error,
        public readonly bool $extractionFailed = false,
    ) {
    }

    public static function imported(int $recipeId, string $slug, string $title): self
    {
        return new self(true, $recipeId, $slug, $title, null);
    }

    public static function alreadyImported(string $error): self
    {
        return new self(false, null, null, null, $error);
    }

    public static function extractionFailed(string $error): self
    {
        return new self(false, null, null, null, $error, extractionFailed: true);
    }
}
