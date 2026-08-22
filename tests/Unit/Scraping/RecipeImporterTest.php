<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scraping;

use App\Repository\RecipeRepository;
use App\Scraping\RecipeImporter;
use App\Tests\Support\InMemoryDatabase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the full fetch -> robots check -> extract -> create pipeline
 * against a MockHandler-backed Guzzle client (zero real HTTP) and a real
 * SQLite RecipeRepository -- this is the shared logic behind both
 * `recipe:import` (CLI) and the admin web UI's "Import a recipe" form, so
 * it's tested once here rather than separately for each caller.
 */
final class RecipeImporterTest extends TestCase
{
    private RecipeRepository $recipes;

    protected function setUp(): void
    {
        $this->recipes = new RecipeRepository(InMemoryDatabase::create());
    }

    private function importer(MockHandler $mock): RecipeImporter
    {
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return new RecipeImporter($this->recipes, $client);
    }

    private const RECIPE_HTML = <<<'HTML'
        <!doctype html>
        <html><head><script type="application/ld+json">
        {
          "@context": "https://schema.org",
          "@type": "Recipe",
          "name": "Focaccia",
          "recipeIngredient": ["500g flour", "7g yeast", "2 tsp salt"],
          "recipeInstructions": [
            {"@type": "HowToStep", "text": "Tip the flour into a bowl."},
            {"@type": "HowToStep", "text": "Knead for 10 minutes."}
          ]
        }
        </script></head><body></body></html>
        HTML;

    // Riverford wraps recipeIngredient in an extra array level -- see
    // JsonLdRecipeExtractor::flattenIngredientNode().
    private const RIVERFORD_STYLE_HTML = <<<'HTML'
        <!doctype html>
        <html><head><script type="application/ld+json">
        {
          "@type": "Recipe",
          "name": "Balsamic BBQ Strawberries",
          "recipeIngredient": [["2 tbsp balsamic vinegar", "300g strawberries"]],
          "recipeInstructions": [
            {"@type": "HowToStep", "text": "Soak skewers in water."},
            {"@type": "HowToStep", "text": "Griddle the strawberries."}
          ]
        }
        </script></head><body></body></html>
        HTML;

    public function testImportsANewRecipeAsADraft(): void
    {
        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(200, [], self::RECIPE_HTML),
        ]));

        $result = $importer->import('https://www.bbcgoodfood.com/recipes/focaccia');

        self::assertTrue($result->success);
        self::assertSame('Focaccia', $result->title);
        self::assertSame('focaccia', $result->slug);
        self::assertNotNull($result->recipeId);

        $recipe = $this->recipes->findById($result->recipeId);
        self::assertNotNull($recipe);
        self::assertSame('draft', $recipe['status']);
        self::assertSame('https://www.bbcgoodfood.com/recipes/focaccia', $recipe['origin']);
        self::assertSame("500g flour\n7g yeast\n2 tsp salt", $recipe['original_ingredients']);
        self::assertSame("1. Tip the flour into a bowl.\n2. Knead for 10 minutes.", $recipe['original_instructions']);
    }

    public function testFlattensRiverfordStyleNestedIngredientArray(): void
    {
        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(200, [], self::RIVERFORD_STYLE_HTML),
        ]));

        $result = $importer->import('https://www.riverford.co.uk/recipes/balsamic-bbq-strawberries');

        self::assertTrue($result->success);
        $recipe = $this->recipes->findById((int) $result->recipeId);
        self::assertNotNull($recipe);
        self::assertSame("2 tbsp balsamic vinegar\n300g strawberries", $recipe['original_ingredients']);
    }

    public function testRefusesToReImportTheSameOriginWithoutForce(): void
    {
        $this->recipes->create('focaccia', 'Focaccia', 'https://example.com/focaccia', 'Flour.', 'Bake it.');

        // No HTTP responses queued -- the origin check must short-circuit
        // before any fetch happens, or MockHandler throws "no more
        // responses" and fails the test that way instead.
        $importer = $this->importer(new MockHandler([]));

        $result = $importer->import('https://example.com/focaccia');

        self::assertFalse($result->success);
        self::assertStringContainsString('Already imported', (string) $result->error);
        // Automation itself still works -- the admin route keeps this on
        // the retry-with-force form rather than bouncing to manual entry.
        self::assertFalse($result->extractionFailed);
    }

    public function testForceReImportsAnAlreadyImportedOrigin(): void
    {
        $this->recipes->create('focaccia', 'Focaccia', 'https://example.com/focaccia', 'Flour.', 'Bake it.');

        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(200, [], self::RECIPE_HTML),
        ]));

        $result = $importer->import('https://example.com/focaccia', force: true);

        self::assertTrue($result->success);
        // A second recipe under a de-duplicated slug, not an overwrite of
        // the first.
        self::assertSame('focaccia-2', $result->slug);
    }

    public function testRobotsTxtDisallowBlocksTheImport(): void
    {
        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nDisallow: /recipes/"),
        ]));

        $result = $importer->import('https://example.com/recipes/focaccia');

        self::assertFalse($result->success);
        self::assertStringContainsString('robots.txt', (string) $result->error);
        // Automation is a dead end for this URL -- the admin route sends
        // this to the manual-entry-with-iframe page.
        self::assertTrue($result->extractionFailed);
    }

    public function testHttpErrorFetchingThePageFails(): void
    {
        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(404),
        ]));

        $result = $importer->import('https://example.com/gone');

        self::assertFalse($result->success);
        self::assertTrue($result->extractionFailed);
        self::assertStringContainsString('404', (string) $result->error);
    }

    public function testNoJsonLdRecipeDataFails(): void
    {
        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(200, [], '<html><body>No structured data here.</body></html>'),
        ]));

        $result = $importer->import('https://example.com/blog-post');

        self::assertFalse($result->success);
        self::assertTrue($result->extractionFailed);
        self::assertStringContainsString('No structured recipe data', (string) $result->error);
    }

    public function testStructuredDataWithNoUsableContentFails(): void
    {
        $html = '<script type="application/ld+json">'
            . '{"@type": "Recipe", "name": "Bare Recipe", "recipeIngredient": [], "recipeInstructions": []}'
            . '</script>';

        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(200, [], $html),
        ]));

        $result = $importer->import('https://example.com/bare');

        self::assertFalse($result->success);
        self::assertTrue($result->extractionFailed);
        self::assertStringContainsString('no usable ingredients or instructions', (string) $result->error);
    }

    public function testDuplicateTitlesGetADeduplicatedSlug(): void
    {
        $this->recipes->create('focaccia', 'Focaccia', null, 'Flour.', 'Bake it.');

        $importer = $this->importer(new MockHandler([
            new Response(200, [], "User-agent: *\nAllow: /"),
            new Response(200, [], self::RECIPE_HTML),
        ]));

        $result = $importer->import('https://www.bbcgoodfood.com/recipes/focaccia-again');

        self::assertTrue($result->success);
        self::assertSame('focaccia-2', $result->slug);
    }
}
