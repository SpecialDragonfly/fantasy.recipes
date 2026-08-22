<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\RecipeJsonLd;
use PHPUnit\Framework\TestCase;

/**
 * The output is used the same way App\Scraping\JsonLdRecipeExtractor reads
 * schema.org JSON-LD back out of other sites -- these tests build a recipe
 * row, decode what RecipeJsonLd produces, and check the round-trip shape
 * rather than comparing raw JSON strings, so field-order changes don't
 * make the tests brittle.
 */
final class RecipeJsonLdTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function recipe(
        string $title = 'Dragon\'s Breath Stew',
        string $ingredients = "500g dragonflesh\n2 onions\n1 tsp salt",
        string $instructions = "1. Sear the dragonflesh.\n2. Simmer for an hour.",
    ): array {
        return [
            'title' => $title,
            'original_ingredients' => $ingredients,
            'original_instructions' => $instructions,
            'created_at' => '2026-01-15 09:30:00',
            'updated_at' => '2026-02-01 12:00:00',
        ];
    }

    public function testBuildsRecipeJsonLdFromOriginalIngredientsAndInstructions(): void
    {
        $json = RecipeJsonLd::build($this->recipe(), null, [], 'https://fantasy.recipes/recipes/dragons-breath-stew');

        self::assertNotNull($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('https://schema.org', $data['@context']);
        self::assertSame('Recipe', $data['@type']);
        self::assertSame("Dragon's Breath Stew", $data['name']);
        self::assertSame('https://fantasy.recipes/recipes/dragons-breath-stew', $data['url']);
        self::assertSame('https://fantasy.recipes/recipes/dragons-breath-stew', $data['mainEntityOfPage']);
        self::assertSame(['@type' => 'Organization', 'name' => 'fantasy.recipes'], $data['author']);
        self::assertSame(['500g dragonflesh', '2 onions', '1 tsp salt'], $data['recipeIngredient']);
        self::assertSame(
            [
                ['@type' => 'HowToStep', 'text' => '1. Sear the dragonflesh.'],
                ['@type' => 'HowToStep', 'text' => '2. Simmer for an hour.'],
            ],
            $data['recipeInstructions'],
        );
        self::assertSame('2026-01-15T09:30:00', $data['datePublished']);
        self::assertSame('2026-02-01T12:00:00', $data['dateModified']);
        self::assertArrayNotHasKey('description', $data);
        self::assertArrayNotHasKey('keywords', $data);
        self::assertArrayNotHasKey('image', $data);
    }

    public function testReturnsNullWhenThereAreNoIngredientsOrInstructions(): void
    {
        $json = RecipeJsonLd::build(
            $this->recipe(ingredients: '', instructions: ''),
            null,
            [],
            'https://fantasy.recipes/recipes/empty',
        );

        self::assertNull($json);
    }

    public function testStillBuildsWhenOnlyOneOfIngredientsOrInstructionsIsPresent(): void
    {
        $json = RecipeJsonLd::build(
            $this->recipe(ingredients: '500g dragonflesh', instructions: ''),
            null,
            [],
            'https://fantasy.recipes/recipes/partial',
        );

        self::assertNotNull($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['500g dragonflesh'], $data['recipeIngredient']);
        self::assertSame([], $data['recipeInstructions']);
    }

    public function testDescriptionComesFromTheStoryBodyAndIsExcerpted(): void
    {
        $longBody = str_repeat('The dragon breathed fire over the pot. ', 20);

        $json = RecipeJsonLd::build(
            $this->recipe(),
            ['narrator' => 'The Dragon', 'body' => $longBody],
            [],
            'https://fantasy.recipes/recipes/dragons-breath-stew',
        );

        self::assertNotNull($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsString($data['description']);
        self::assertLessThanOrEqual(300, mb_strlen($data['description']));
        self::assertStringEndsWith('...', $data['description']);
    }

    public function testEmptyStoryBodyProducesNoDescription(): void
    {
        $json = RecipeJsonLd::build(
            $this->recipe(),
            ['narrator' => 'The Dragon', 'body' => '   '],
            [],
            'https://fantasy.recipes/recipes/dragons-breath-stew',
        );

        self::assertNotNull($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('description', $data);
    }

    public function testKeywordsComeFromTagNamesJoinedByComma(): void
    {
        $json = RecipeJsonLd::build(
            $this->recipe(),
            null,
            [['id' => 1, 'name' => 'Main'], ['id' => 2, 'name' => 'Love potion']],
            'https://fantasy.recipes/recipes/dragons-breath-stew',
        );

        self::assertNotNull($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Main, Love potion', $data['keywords']);
    }

    public function testALiteralClosingScriptTagInTextCannotBreakOutOfTheEmbeddingScriptTag(): void
    {
        $json = RecipeJsonLd::build(
            $this->recipe(instructions: "1. Stir well.\n</script><script>alert(1)</script>"),
            null,
            [],
            'https://fantasy.recipes/recipes/dragons-breath-stew',
        );

        self::assertNotNull($json);
        self::assertStringNotContainsString('</script>', $json);

        // And still round-trips to the original text once decoded -- the
        // encoding is HTML-safe, not lossy.
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(
            ['1. Stir well.', '</script><script>alert(1)</script>'],
            array_column($data['recipeInstructions'], 'text'),
        );
    }
}
