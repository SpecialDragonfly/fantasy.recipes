<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RecipeRepository;
use App\Repository\TagRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class TagRepositoryTest extends TestCase
{
    private PDO $pdo;
    private TagRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repository = new TagRepository($this->pdo);
    }

    public function testAllReturnsTagsAlphabetically(): void
    {
        $this->repository->create('Soup');
        $this->repository->create('Dessert');
        $this->repository->create('Main');

        $names = array_column($this->repository->all(), 'name');

        self::assertSame(['Dessert', 'Main', 'Soup'], $names);
    }

    public function testFindByNameAndFindByIdReturnNullWhenMissing(): void
    {
        self::assertNull($this->repository->findByName('Nonexistent'));
        self::assertNull($this->repository->findById(999));
    }

    public function testRenameChangesTheNameNotTheId(): void
    {
        $id = $this->repository->create('Starter');

        $this->repository->rename($id, 'Appetizer');

        self::assertNull($this->repository->findByName('Starter'));
        $renamed = $this->repository->findById($id);
        self::assertNotNull($renamed);
        self::assertSame('Appetizer', $renamed['name']);
    }

    public function testNameUniquenessIsEnforcedAtTheDatabaseLevel(): void
    {
        $this->repository->create('Dessert');

        $this->expectException(\PDOException::class);
        $this->repository->create('Dessert');
    }

    private function createRecipe(string $slug = 'a-recipe'): int
    {
        return (new RecipeRepository($this->pdo))->create($slug, 'A Recipe', null, '', '');
    }

    public function testSetTagsForRecipeSyncsExactlyTheGivenSet(): void
    {
        $recipeId = $this->createRecipe();
        $starter = $this->repository->create('Starter');
        $dessert = $this->repository->create('Dessert');
        $loveP = $this->repository->create('Love potion');

        $this->repository->setTagsForRecipe($recipeId, [$starter, $dessert]);
        self::assertSame(
            ['Dessert', 'Starter'],
            array_column($this->repository->tagsForRecipe($recipeId), 'name'),
        );

        // Re-syncing to a different set replaces, not merges/appends.
        $this->repository->setTagsForRecipe($recipeId, [$loveP]);
        self::assertSame(
            ['Love potion'],
            array_column($this->repository->tagsForRecipe($recipeId), 'name'),
        );
    }

    public function testSetTagsForRecipeDeduplicatesRepeatedIds(): void
    {
        $recipeId = $this->createRecipe();
        $tagId = $this->repository->create('Main');

        $this->repository->setTagsForRecipe($recipeId, [$tagId, $tagId, $tagId]);

        self::assertCount(1, $this->repository->tagsForRecipe($recipeId));
    }

    public function testDeletingATagCascadesOutOfRecipeTagsPivot(): void
    {
        $recipeId = $this->createRecipe();
        $tagId = $this->repository->create('Main');
        $this->repository->setTagsForRecipe($recipeId, [$tagId]);

        $this->repository->delete($tagId);

        self::assertCount(0, $this->repository->tagsForRecipe($recipeId));

        $statement = $this->pdo->query('SELECT COUNT(*) FROM recipe_tags');
        self::assertNotFalse($statement);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testTagIdsByRecipeIdsBatchesMultipleRowsInOneCall(): void
    {
        $first = $this->createRecipe('a-recipe');
        $second = $this->createRecipe('b-recipe');
        $tagId = $this->repository->create('Main');

        $this->repository->setTagsForRecipe($first, [$tagId]);
        // $second is deliberately left untagged.

        $result = $this->repository->tagIdsByRecipeIds([$first, $second]);

        self::assertSame([$tagId], $result[$first]);
        self::assertArrayNotHasKey($second, $result);
    }

    public function testTagIdsByRecipeIdsReturnsEmptyArrayForEmptyInput(): void
    {
        self::assertSame([], $this->repository->tagIdsByRecipeIds([]));
    }
}
