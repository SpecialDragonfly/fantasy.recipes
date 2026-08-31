<?php

declare(strict_types=1);

namespace App\Scraping;

use App\Repository\RecipeRepository;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The one-URL-in, one-draft-recipe-out import pipeline (spec.md -- Content
 * Pipeline step 1; ideas.md item 5: "An individual recipe importer needs
 * building -- give a link, and it imports that specific recipe"). Shared by
 * both `recipe:import` (CLI -- see Console\ImportRecipeCommand) and the
 * admin web UI's "Import a recipe" form (src/Routes/admin.php's
 * /admin/recipes/import routes), so the fetch/extract/validate pipeline
 * only lives in one place. There is no staging table -- this writes
 * straight into `recipes` at status = 'draft', the same row an admin then
 * rewrites/tags/translates/publishes in place (see
 * db/migrations/20260816120300_create_recipes_table.php).
 *
 * One recipe per call, admin-initiated -- no discovery, no rate limiting
 * across many hosts (that only mattered for the old unattended multi-site
 * crawl, since deleted). Confirmed working against BBC Good Food,
 * HelloFresh (UK), and Riverford -- all three embed schema.org JSON-LD
 * Recipe data on their recipe pages and don't disallow individual recipe
 * paths in robots.txt -- but nothing here is hardcoded to just those three;
 * any site with the same structured data works the same way, and one
 * without it fails cleanly (see the "no structured recipe data" result
 * below) rather than falling back to guessed HTML scraping -- the admin web
 * route sends that failure (and a robots.txt disallow, and a fetch/HTTP
 * error) on to a manual-entry page with the source open in an iframe
 * alongside instead (see src/Routes/admin.php's /admin/recipes/import/manual
 * route), rather than just showing an error and stopping. See
 * RecipeImportResult's docblock for how the two kinds of failure are told
 * apart.
 *
 * Ingredients are carried through effectively verbatim (a listing of
 * ingredients is a fact, not an expression); instructions get a
 * *mechanical* rewrite -- deterministic re-segmentation into this site's
 * own numbered-step format, not an AI paraphrase. The real narrative/
 * editorial effort (fantasy title, narrator, Story, narrator_recipe) still
 * happens afterward through the admin UI.
 */
final class RecipeImporter
{
    public function __construct(
        private readonly RecipeRepository $recipes,
        private readonly ClientInterface $http,
    ) {
    }

    public function import(string $url, bool $force = false): RecipeImportResult
    {
        $existing = $this->recipes->findByOrigin($url);
        if ($existing !== null && !$force) {
            return RecipeImportResult::alreadyImported(sprintf(
                'Already imported as recipe #%d ("%s"). Import again anyway if you meant to re-import it.',
                $existing['id'],
                $existing['title'],
            ));
        }

        $userAgent = ($_ENV['SCRAPER_USER_AGENT'] ?? '') !== ''
            ? $_ENV['SCRAPER_USER_AGENT']
            : 'fantasyrecipes importer (+https://fantasyrecipes.co.uk)';

        $robotsChecker = new RobotsTxtChecker($this->http);
        if (!$robotsChecker->isAllowedForUrl($userAgent, $url)) {
            return RecipeImportResult::extractionFailed('robots.txt disallows fetching this URL.');
        }

        try {
            $response = $this->http->request('GET', $url, ['http_errors' => false]);
        } catch (GuzzleException $e) {
            return RecipeImportResult::extractionFailed('Fetch failed: ' . $e->getMessage());
        }

        if ($response->getStatusCode() >= 400) {
            return RecipeImportResult::extractionFailed('HTTP ' . $response->getStatusCode() . ' fetching ' . $url);
        }

        $extractor = new JsonLdRecipeExtractor();
        $data = $extractor->extract((string) $response->getBody(), $url);

        if ($data === null) {
            return RecipeImportResult::extractionFailed(
                'No structured recipe data (schema.org JSON-LD) found on that page. '
                . 'This site needs a recipe entered by hand instead.',
            );
        }

        $ingredientsRaw = trim((string) $data->ingredientsRaw);
        $instructionsRaw = self::mechanicallyRewriteInstructions((string) $data->instructionsRaw);

        if ($ingredientsRaw === '' && $instructionsRaw === '') {
            return RecipeImportResult::extractionFailed(
                'Structured recipe data was found, but had no usable ingredients or instructions.',
            );
        }

        $title = self::placeholderTitle($data->title);
        $slug = $this->uniqueSlug($title);

        $recipeId = $this->recipes->create($slug, $title, $url, $ingredientsRaw, $instructionsRaw);

        return RecipeImportResult::imported($recipeId, $slug, $title);
    }

    /**
     * Deterministic, not AI-authored: strips whatever markup the source
     * used, discards the source's own paragraph/list structure entirely,
     * and re-segments the text into this site's own numbered-step format.
     * Sentence-level wording is untouched -- this only changes structure/
     * formatting, which is the point: the underlying steps are functional
     * facts either way, and "not a copy of the source's own formatting and
     * structure" is the legal safeguard this rests on (bare ingredient/step
     * facts aren't copyrightable, only a source's specific expression is).
     *
     * Also strips lettered sub-step markers ("a)", "b)", ...) as well as
     * numeric/bulleted ones -- HelloFresh packs multiple lettered
     * sub-steps as one HTML blob per schema.org HowToStep (e.g. "<p>a)
     * Bring a pan of water to the boil...</p><p>b) Halve the
     * potatoes...</p>"), and without this they'd survive as "1. a)
     * Bring..." after renumbering.
     */
    public static function mechanicallyRewriteInstructions(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $text = preg_replace('/<\s*(br|\/p|\/li|\/div|\/tr)\s*\/?>/i', "\n", $raw) ?? $raw;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\r\n?/', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            static fn (string $line): bool => $line !== '',
        ));

        $steps = [];

        foreach ($lines as $line) {
            $line = preg_replace('/^(\d+[.)]|[a-zA-Z][.)]|[-*•])\s*/', '', $line) ?? $line;
            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) > 220) {
                $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9])/', $line) ?: [$line];
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if ($sentence !== '') {
                        $steps[] = $sentence;
                    }
                }
            } else {
                $steps[] = $line;
            }
        }

        if ($steps === []) {
            return '';
        }

        $numbered = [];
        foreach ($steps as $index => $step) {
            $numbered[] = ($index + 1) . '. ' . $step;
        }

        return implode("\n", $numbered);
    }

    /**
     * Placeholder only -- the real in-world name is an admin edit away
     * (spec.md's "no mundane title" rule is a publish-time guarantee; every
     * imported recipe starts at status = 'draft').
     */
    private static function placeholderTitle(?string $scrapedTitle): string
    {
        $title = trim((string) $scrapedTitle);
        if ($title === '') {
            $title = 'Untitled Import';
        }

        return mb_substr($title, 0, 255);
    }

    /**
     * Same slug format the admin edit form enforces
     * (^[a-z0-9]+(-[a-z0-9]+)*$), deduplicated against recipes.slug's
     * unique index by appending a numeric suffix.
     */
    private function uniqueSlug(string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'recipe';
        }

        if ($this->recipes->findBySlug($base) === null) {
            return $base;
        }

        $suffix = 2;
        while ($this->recipes->findBySlug($base . '-' . $suffix) !== null) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }
}
