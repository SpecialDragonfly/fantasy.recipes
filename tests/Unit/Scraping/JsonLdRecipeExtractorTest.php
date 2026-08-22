<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scraping;

use App\Scraping\JsonLdRecipeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * The highest-value, highest-risk-of-subtle-bugs piece of the scraper:
 * schema.org's `recipeInstructions` field varies wildly across real-world
 * publishers (plain string / flat string array / HowToStep array / nested
 * HowToSection array), and JSON-LD itself can appear as a bare object, a
 * list of objects, or wrapped in "@graph". Every shape gets its own fixture
 * under tests/Fixtures/Scraping/ rather than being faked inline, so these
 * read close to what a real page actually ships.
 */
final class JsonLdRecipeExtractorTest extends TestCase
{
    private JsonLdRecipeExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new JsonLdRecipeExtractor();
    }

    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../../Fixtures/Scraping/' . $name;
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, "Missing fixture: $name");

        return $contents;
    }

    public function testPlainStringInstructions(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('recipe-plain-string-instructions.html'),
            'https://example.com/dragonfire-chili',
        );

        self::assertNotNull($data);
        self::assertSame('Dragonfire Chili', $data->title);
        self::assertSame(
            "1 lb ground beef\n2 cans kidney beans\n1 onion, diced",
            $data->ingredientsRaw,
        );
        self::assertSame('Brown the beef. Add beans and onion. Simmer for 30 minutes.', $data->instructionsRaw);
        self::assertSame('https://example.com/images/chili.jpg', $data->imageUrl);
    }

    public function testArrayOfStringInstructionsAndMultipleImages(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('recipe-array-instructions.html'),
            'https://example.com/moon-onion-soup',
        );

        self::assertNotNull($data);
        self::assertSame('Moon-Onion Soup', $data->title);
        self::assertSame(
            "Slice the onions.\nSimmer in stock for 20 minutes.\nSeason with salt.",
            $data->instructionsRaw,
        );
        // First of multiple image URLs.
        self::assertSame('https://example.com/images/soup-1.jpg', $data->imageUrl);
    }

    public function testHowToStepArrayInstructionsAndImageObject(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('recipe-howto-steps.html'),
            'https://example.com/kiln-baked-bread',
        );

        self::assertNotNull($data);
        self::assertSame('Kiln-Baked Bread', $data->title);
        self::assertSame(
            "Mix flour, yeast, and salt.\nAdd water and knead for 10 minutes.\n"
            . "Prove for 1 hour, then bake at 220C for 30 minutes.",
            $data->instructionsRaw,
        );
        self::assertSame('https://example.com/images/bread.jpg', $data->imageUrl);
    }

    public function testNestedHowToSectionsFlattenWithHeadings(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('recipe-howto-sections.html'),
            'https://example.com/layered-dragonflesh-pie',
        );

        self::assertNotNull($data);
        self::assertSame(
            "Make the filling:\nBrown the meat.\nAdd carrots and simmer 40 minutes."
            . "\nAssemble the pie:\nLine a dish with pastry.\nFill and top with pastry, bake at 200C for 25 minutes.",
            $data->instructionsRaw,
        );
    }

    public function testNestedIngredientArrayFlattensOneLevel(): void
    {
        // Riverford wraps recipeIngredient in an extra array level --
        // [["a", "b"]] instead of ["a", "b"] -- rather than a flat list of
        // strings. See JsonLdRecipeExtractor::flattenIngredientNode().
        $data = $this->extractor->extract(
            $this->fixture('recipe-nested-ingredient-array.html'),
            'https://example.com/nested-turnip-bake',
        );

        self::assertNotNull($data);
        self::assertSame(
            "2 turnips, diced\n1 tbsp oil\nsalt & pepper",
            $data->ingredientsRaw,
        );
    }

    public function testGraphWrapperAndArrayTypeField(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('recipe-graph-wrapper.html'),
            'https://example.com/witchs-stew',
        );

        self::assertNotNull($data);
        self::assertSame("Witch's Stew", $data->title);
        self::assertSame('Throw it all in the cauldron and stir.', $data->instructionsRaw);
        self::assertSame('https://example.com/images/stew.jpg', $data->imageUrl);
    }

    public function testFindsRecipeInSecondOfMultipleJsonLdBlocks(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('recipe-second-of-multiple-blocks.html'),
            'https://example.com/second-block-stew',
        );

        self::assertNotNull($data);
        self::assertSame('Second Block Stew', $data->title);
    }

    public function testNoRecipeTypePresentReturnsNull(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('no-recipe-data.html'),
            'https://example.com/about',
        );

        self::assertNull($data);
    }

    public function testNoJsonLdAtAllReturnsNull(): void
    {
        $data = $this->extractor->extract(
            $this->fixture('no-json-ld-at-all.html'),
            'https://example.com/blank',
        );

        self::assertNull($data);
    }

    public function testMalformedJsonIsSkippedWithoutError(): void
    {
        $html = '<script type="application/ld+json">{not valid json</script>';

        self::assertNull($this->extractor->extract($html, 'https://example.com/broken'));
    }

    public function testEmptyIngredientsAndInstructionsBecomeNull(): void
    {
        $html = '<script type="application/ld+json">'
            . '{"@type": "Recipe", "name": "Bare Recipe", "recipeIngredient": [], "recipeInstructions": []}'
            . '</script>';

        $data = $this->extractor->extract($html, 'https://example.com/bare');

        self::assertNotNull($data);
        self::assertSame('Bare Recipe', $data->title);
        self::assertNull($data->ingredientsRaw);
        self::assertNull($data->instructionsRaw);
        self::assertNull($data->imageUrl);
    }
}
