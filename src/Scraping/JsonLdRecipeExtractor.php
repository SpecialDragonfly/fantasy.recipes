<?php

declare(strict_types=1);

namespace App\Scraping;

/**
 * Extracts schema.org Recipe structured data (JSON-LD) from a raw HTML
 * page. Most modern recipe blogs/publishers embed this for Google's recipe
 * rich-snippets, so this one generic extractor covers a wide range of sites
 * (confirmed against BBC Good Food, HelloFresh, and Riverford -- see
 * RecipeImporter) without needing bespoke per-site HTML parsing. Returns
 * null (not an exception) when no parseable Recipe data is found -- that's
 * the normal "needs manual admin entry via the admin UI" case, not treated
 * as a failure.
 *
 * `recipeInstructions` in particular varies a lot across real-world
 * publishers: a plain string, a flat array of strings, an array of
 * HowToStep objects ({"@type":"HowToStep","text":"..."}), or an array of
 * HowToSection objects (each with its own "name" heading and a nested
 * "itemListElement" list of HowToSteps). All of those are flattened here
 * into one newline-joined instruction list.
 */
final class JsonLdRecipeExtractor
{
    public function extract(string $html, string $sourceUrl): ?ScrapedRecipeData
    {
        foreach (self::findJsonLdBlocks($html) as $block) {
            /** @var mixed $decoded */
            $decoded = json_decode($block, true);

            if (!is_array($decoded)) {
                continue;
            }

            $recipe = self::findRecipeNode($decoded);

            if ($recipe !== null) {
                return self::mapToData($recipe);
            }
        }

        return null;
    }

    /**
     * @return list<string> raw contents of every <script type="application/ld+json"> block
     */
    private static function findJsonLdBlocks(string $html): array
    {
        $matched = preg_match_all(
            '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $html,
            $matches,
        );

        return $matched > 0 ? $matches[1] : [];
    }

    /**
     * A decoded JSON-LD block can be: a single node (assoc array with
     * @type), a bare list of nodes, or a node with an "@graph" wrapper
     * containing a list of nodes. Normalizes to a search over candidate
     * nodes and returns the first one typed "Recipe".
     *
     * @param array<mixed> $decoded
     * @return array<string, mixed>|null
     */
    private static function findRecipeNode(array $decoded): ?array
    {
        if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
            $candidates = $decoded['@graph'];
        } elseif (array_is_list($decoded)) {
            $candidates = $decoded;
        } else {
            $candidates = [$decoded];
        }

        foreach ($candidates as $node) {
            if (is_array($node) && self::hasRecipeType($node)) {
                /** @var array<string, mixed> $node */
                return $node;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     */
    private static function hasRecipeType(array $node): bool
    {
        $type = $node['@type'] ?? null;

        if (is_string($type)) {
            return strcasecmp($type, 'Recipe') === 0;
        }

        if (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t) && strcasecmp($t, 'Recipe') === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function mapToData(array $recipe): ScrapedRecipeData
    {
        return new ScrapedRecipeData(
            title: self::stringOrNull($recipe['name'] ?? null),
            ingredientsRaw: self::extractIngredients($recipe['recipeIngredient'] ?? $recipe['ingredients'] ?? null),
            instructionsRaw: self::extractInstructions($recipe['recipeInstructions'] ?? null),
            imageUrl: self::extractImage($recipe['image'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function extractIngredients(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        $lines = [];
        self::flattenIngredientNode($value, $lines);

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * `recipeIngredient` should be a flat array of strings per schema.org,
     * but at least one real publisher (Riverford) wraps it in an extra
     * array level -- `[["500g flour", "7g yeast"]]` instead of `["500g
     * flour", "7g yeast"]`. Flattening handles both shapes without needing
     * a site-specific extractor.
     *
     * @param array<mixed> $node
     * @param list<string> $lines
     */
    private static function flattenIngredientNode(array $node, array &$lines): void
    {
        foreach ($node as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);

                if ($trimmed !== '') {
                    $lines[] = $trimmed;
                }
            } elseif (is_array($item)) {
                self::flattenIngredientNode($item, $lines);
            }
        }
    }

    private static function extractInstructions(mixed $value): ?string
    {
        $asString = self::stringOrNull($value);

        if ($asString !== null) {
            return $asString;
        }

        if (!is_array($value)) {
            return null;
        }

        $lines = [];
        self::flattenInstructionNode($value, $lines);

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * @param array<mixed> $nodeOrList
     * @param list<string> $lines
     */
    private static function flattenInstructionNode(array $nodeOrList, array &$lines): void
    {
        if (!array_is_list($nodeOrList)) {
            // A single HowToStep/HowToSection object rather than a list of
            // them.
            self::flattenSingleInstructionNode($nodeOrList, $lines);

            return;
        }

        foreach ($nodeOrList as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);

                if ($trimmed !== '') {
                    $lines[] = $trimmed;
                }
            } elseif (is_array($item)) {
                self::flattenSingleInstructionNode($item, $lines);
            }
        }
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $lines
     */
    private static function flattenSingleInstructionNode(array $node, array &$lines): void
    {
        // HowToStep: {"@type": "HowToStep", "text": "..."}
        $text = self::stringOrNull($node['text'] ?? null);

        if ($text !== null) {
            $lines[] = $text;

            return;
        }

        // HowToSection: {"@type": "HowToSection", "name": "...", "itemListElement": [...]}
        if (isset($node['itemListElement']) && is_array($node['itemListElement'])) {
            $heading = self::stringOrNull($node['name'] ?? null);

            if ($heading !== null) {
                $lines[] = $heading . ':';
            }

            self::flattenInstructionNode($node['itemListElement'], $lines);
        }
    }

    private static function extractImage(mixed $value): ?string
    {
        $asString = self::stringOrNull($value);

        if ($asString !== null) {
            return $asString;
        }

        if (!is_array($value)) {
            return null;
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                $resolved = self::extractImage($item);

                if ($resolved !== null) {
                    return $resolved;
                }
            }

            return null;
        }

        // ImageObject: {"@type": "ImageObject", "url": "..."}
        return self::stringOrNull($value['url'] ?? null);
    }
}
