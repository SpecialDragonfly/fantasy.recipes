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
}
