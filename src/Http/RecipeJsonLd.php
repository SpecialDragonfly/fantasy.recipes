<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Builds the schema.org Recipe JSON-LD block for a published recipe's
 * detail page (templates/recipes/detail.twig) -- the same structured-data
 * shape App\Scraping\JsonLdRecipeExtractor reads back out of other sites
 * when importing, mapped in reverse.
 *
 * `recipeIngredient`/`recipeInstructions` use OriginalIngredients/
 * OriginalInstructions (the mundane truth), not NarratorRecipe -- Google's
 * Recipe rich-result guidelines want the literal ingredient list and
 * steps, not in-world ritual prose, and OriginalIngredients/
 * OriginalInstructions are already present, uncollapsed, in this same
 * page's HTML behind a <details> element (spec.md -- Immersion Rules), so
 * emitting them here doesn't expose anything a crawler couldn't already
 * read directly. `name` is still the fantasy Title, though -- that's what
 * shows as the clickable headline in search results, and there's no
 * separate mundane title to use instead (spec.md -- Domain Model: Recipe).
 *
 * No `image` property -- this app has no recipe image feature (removed
 * entirely, see architecture.md -- Data Model Notes) and there is nothing
 * truthful to put there.
 *
 * Returns a ready-to-embed JSON string (not an array), so the caller can
 * drop it straight into a `<script type="application/ld+json">` tag with
 * Twig's `|raw` filter -- encoded with JSON_HEX_TAG so admin-entered text
 * containing a literal "</script>" can't break out of the tag early. Twig
 * autoescaping is deliberately bypassed here (via |raw) because this is
 * already-serialized JSON, not HTML -- running it through Twig's HTML
 * escaper too would corrupt it (mangling quotes) rather than making it
 * safer.
 *
 * Returns null when there's nothing worth publishing (no ingredients and
 * no instructions -- an edge case the DB schema technically allows: both
 * columns are NOT NULL but may be empty strings) rather than emitting a
 * Recipe block with two empty arrays.
 */
final class RecipeJsonLd
{
    /**
     * @param array<string, mixed> $recipe expects title, original_ingredients, original_instructions, created_at, updated_at
     * @param array<string, mixed>|null $story expects body, when present
     * @param list<array<string, mixed>> $tags expects name per entry
     */
    public static function build(array $recipe, ?array $story, array $tags, string $canonicalUrl): ?string
    {
        $ingredients = self::lines((string) $recipe['original_ingredients']);
        $instructions = self::lines((string) $recipe['original_instructions']);

        if ($ingredients === [] && $instructions === []) {
            return null;
        }

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => (string) $recipe['title'],
            'url' => $canonicalUrl,
            'mainEntityOfPage' => $canonicalUrl,
            'author' => ['@type' => 'Organization', 'name' => 'fantasy.recipes'],
            'datePublished' => self::isoDate((string) $recipe['created_at']),
            'dateModified' => self::isoDate((string) $recipe['updated_at']),
            'recipeIngredient' => $ingredients,
            'recipeInstructions' => array_map(
                static fn (string $step): array => ['@type' => 'HowToStep', 'text' => $step],
                $instructions,
            ),
        ];

        if ($story !== null && trim((string) $story['body']) !== '') {
            $data['description'] = self::excerpt((string) $story['body']);
        }

        $keywords = array_values(array_filter(array_map(
            static fn (array $tag): string => (string) $tag['name'],
            $tags,
        )));

        if ($keywords !== []) {
            $data['keywords'] = implode(', ', $keywords);
        }

        return (string) json_encode(
            $data,
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * recipes.created_at/updated_at are stored as plain 'Y-m-d H:i:s'
     * (MySQL DATETIME / SQLite text) with no timezone, so this can't
     * produce a truly correct offset -- schema.org accepts a bare ISO 8601
     * datetime without one, which is the honest representation of what's
     * actually known here.
     */
    private static function isoDate(string $datetime): string
    {
        $timestamp = strtotime($datetime);

        return $timestamp === false ? $datetime : date('Y-m-d\TH:i:s', $timestamp);
    }

    /**
     * A search-result-snippet-sized excerpt of the Story, not the whole
     * thing -- `description` is meant to summarize, not duplicate the page
     * body.
     */
    private static function excerpt(string $body): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $body));

        return mb_strlen($flat) > 300 ? mb_substr($flat, 0, 297) . '...' : $flat;
    }
}
