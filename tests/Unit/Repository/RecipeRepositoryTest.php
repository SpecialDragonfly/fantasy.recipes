<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RecipeRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class RecipeRepositoryTest extends TestCase
{
    private PDO $pdo;
    private RecipeRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repository = new RecipeRepository($this->pdo);
    }

    public function testCreateStartsAsADraftWithNoNarratorOrStory(): void
    {
        $id = $this->repository->create('dragons-stew', 'Dragon Stew', 'https://example.com/stew', 'Beef.', 'Simmer.');

        $recipe = $this->repository->findById($id);
        self::assertNotNull($recipe);
        self::assertSame('draft', $recipe['status']);
        self::assertNull($recipe['narrator']);
        self::assertNull($recipe['narrator_recipe']);
        self::assertNull($recipe['story_id']);
        self::assertSame('https://example.com/stew', $recipe['origin']);
    }

    public function testFindByOriginFindsAnImportedRecipeByItsSourceUrl(): void
    {
        $this->repository->create('dragons-stew', 'Dragon Stew', 'https://example.com/stew', 'Beef.', 'Simmer.');

        self::assertNotNull($this->repository->findByOrigin('https://example.com/stew'));
        self::assertNull($this->repository->findByOrigin('https://example.com/nope'));
    }

    public function testUpdateChangesSlugAndTitleWithoutTouchingStatus(): void
    {
        $id = $this->repository->create('dragons-stew', 'Dragon Stew', null, '', '');
        $this->repository->setPublished($id, true);

        $this->repository->update($id, 'dragons-stew-supreme', 'Dragon Stew Supreme');

        $recipe = $this->repository->findById($id);
        self::assertNotNull($recipe);
        self::assertSame('dragons-stew-supreme', $recipe['slug']);
        self::assertSame('Dragon Stew Supreme', $recipe['title']);
        // update() doesn't touch status -- setPublished() is the only path
        // that does (see update()'s docblock).
        self::assertSame('published', $recipe['status']);
    }

    public function testSetPublishedTogglesStatusBothWays(): void
    {
        $id = $this->repository->create('dragons-stew', 'Dragon Stew', null, '', '');

        $this->repository->setPublished($id, true);
        $afterPublish = $this->repository->findById($id);
        self::assertNotNull($afterPublish);
        self::assertSame('published', $afterPublish['status']);

        $this->repository->setPublished($id, false);
        $afterUnpublish = $this->repository->findById($id);
        self::assertNotNull($afterUnpublish);
        self::assertSame('draft', $afterUnpublish['status']);
    }

    public function testUpdateNarratorRecipeSetsBothFieldsTogether(): void
    {
        $id = $this->repository->create('dragons-stew', 'Dragon Stew', null, '', '');

        $this->repository->updateNarratorRecipe($id, 'A dragon', 'Simmer the dragonflesh.');

        $recipe = $this->repository->findById($id);
        self::assertNotNull($recipe);
        self::assertSame('A dragon', $recipe['narrator']);
        self::assertSame('Simmer the dragonflesh.', $recipe['narrator_recipe']);
    }

    public function testListPublishedOnlyReturnsPublishedRecipes(): void
    {
        $draftId = $this->repository->create('draft-recipe', 'Draft', null, '', '');
        $publishedId = $this->repository->create('published-recipe', 'Published', null, '', '');
        $this->repository->setPublished($publishedId, true);

        $ids = array_column($this->repository->listPublished(), 'id');

        self::assertContains($publishedId, $ids);
        self::assertNotContains($draftId, $ids);
    }

    public function testCountDraftsCountsOnlyDraftStatus(): void
    {
        $this->repository->create('a', 'A', null, '', '');
        $publishedId = $this->repository->create('b', 'B', null, '', '');
        $this->repository->setPublished($publishedId, true);

        self::assertSame(1, $this->repository->countDrafts());
    }

    public function testListAllFilteredByStatus(): void
    {
        $this->repository->create('a', 'A', null, '', '');
        $publishedId = $this->repository->create('b', 'B', null, '', '');
        $this->repository->setPublished($publishedId, true);

        $published = $this->repository->listAllFiltered(50, 0, null, null, 'published');

        self::assertCount(1, $published);
        self::assertSame($publishedId, $published[0]['id']);
    }

    public function testDeleteRemovesTheRecipe(): void
    {
        $id = $this->repository->create('dragons-stew', 'Dragon Stew', null, '', '');

        $this->repository->delete($id);

        self::assertNull($this->repository->findById($id));
    }

    public function testListPublishedBrowseAndsTermsAcrossTitleAndIngredients(): void
    {
        // Term satisfied by an ingredient line, another by the title.
        $roastChicken = $this->publish('roast-chicken', 'Roast Chicken', "chicken thighs\ngarlic cloves\nolive oil");
        // Has "garlic" but no "chicken" anywhere.
        $this->publish('garlic-bread', 'Garlic Bread', "bread\nbutter\ngarlic");
        // Has "chicken" but no "garlic" anywhere.
        $this->publish('chicken-soup', 'Chicken Soup', "chicken\nwater\nsalt");
        // Matches both terms but is still a draft.
        $draftId = $this->repository->create('chicken-garlic-stew', 'Chicken Garlic Stew', null, 'chicken\ngarlic', '');

        $ids = array_column($this->repository->listPublishedBrowse(['chicken', 'garlic']), 'id');

        self::assertSame([$roastChicken], $ids);
        self::assertNotContains($draftId, $ids);
    }

    public function testListPublishedBrowseMatchesATermFoundOnlyInAnIngredient(): void
    {
        $id = $this->publish('roast-chicken', 'Roast Chicken', "chicken thighs\ngarlic cloves\nolive oil");

        self::assertSame([$id], array_column($this->repository->listPublishedBrowse(['thighs']), 'id'));
        self::assertSame([$id], array_column($this->repository->listPublishedBrowse(['roast']), 'id'));
        self::assertSame([], $this->repository->listPublishedBrowse(['pineapple']));
    }

    public function testListPublishedBrowseComposesWithTheTagFilter(): void
    {
        $tagged = $this->publish('roast-chicken', 'Roast Chicken', "chicken thighs\ngarlic");
        $untagged = $this->publish('chicken-curry', 'Chicken Curry', "chicken\ngarlic\ncream");
        $tagId = $this->seedTag('dinner');
        $this->tagRecipe($tagged, $tagId);

        $ids = array_column($this->repository->listPublishedBrowse(['chicken'], $tagId), 'id');

        self::assertSame([$tagged], $ids);
        self::assertNotContains($untagged, $ids);
    }

    public function testCountPublishedBrowseMatchesTheListedResult(): void
    {
        $this->publish('roast-chicken', 'Roast Chicken', "chicken\ngarlic");
        $this->publish('chicken-curry', 'Chicken Curry', "chicken\ngarlic\ncream");
        $this->publish('garlic-bread', 'Garlic Bread', "bread\ngarlic");

        self::assertSame(2, $this->repository->countPublishedBrowse(['chicken', 'garlic']));
        self::assertSame(3, $this->repository->countPublishedBrowse(['garlic']));
    }

    public function testListPublishedBrowseTreatsWildcardCharactersLiterally(): void
    {
        $this->publish('roast-chicken', 'Roast Chicken', "chicken\ngarlic");

        self::assertSame([], $this->repository->listPublishedBrowse(['ch%en']));
        self::assertSame([], $this->repository->listPublishedBrowse(['chi_ken']));
    }

    private function publish(string $slug, string $title, string $ingredients): int
    {
        $id = $this->repository->create($slug, $title, null, $ingredients, '');
        $this->repository->setPublished($id, true);

        return $id;
    }

    private function seedTag(string $name): int
    {
        $statement = $this->pdo->prepare('INSERT INTO tags (name) VALUES (:name)');
        $statement->execute(['name' => $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function tagRecipe(int $recipeId, int $tagId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recipe_tags (recipe_id, tag_id) VALUES (:recipe_id, :tag_id)',
        );
        $statement->execute(['recipe_id' => $recipeId, 'tag_id' => $tagId]);
    }
}
