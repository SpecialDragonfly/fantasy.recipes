<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Full-text search across recipes.title, .original_ingredients,
 * .original_instructions, and .narrator_recipe, so a mundane term ("beef")
 * and a ritual term ("dragonflesh") both surface the same recipe -- see
 * spec.md -- Search.
 *
 * Search is a guest-facing feature (spec.md -- Roles & Permissions: guests
 * get full read access to *published* content). Implementations must only
 * ever return published recipes -- there is no "include unpublished" mode
 * on this interface; admin browsing of unpublished content is a separate
 * concern (an admin listing route, not this class).
 *
 * @phpstan-type RecipeSearchResult array{id: int, slug: string, title: string}
 */
interface RecipeSearch
{
    /**
     * @return list<RecipeSearchResult>
     */
    public function search(string $query, int $limit = 20, int $offset = 0): array;
}
