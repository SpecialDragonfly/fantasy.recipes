<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\PersonalRecipeRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Every method here takes the acting user's id and folds it into the
 * query -- these tests are as much about proving ownership scoping (a
 * user can never read/change/delete another user's row via this
 * repository) as they are about basic CRUD.
 */
final class PersonalRecipeRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PersonalRecipeRepository $repository;
    private int $ownerId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->repository = new PersonalRecipeRepository($this->pdo);
        $this->ownerId = InMemoryDatabase::seedUser($this->pdo, 'owner');
        $this->otherUserId = InMemoryDatabase::seedUser($this->pdo, 'someoneelse');
    }

    public function testCreateAndFindByIdForUser(): void
    {
        $id = $this->repository->create($this->ownerId, 'Nan\'s Stew', 'Beef. Carrots.', 'Simmer for hours.');

        $recipe = $this->repository->findByIdForUser($id, $this->ownerId);
        self::assertNotNull($recipe);
        self::assertSame('Nan\'s Stew', $recipe['title']);
        self::assertSame('Beef. Carrots.', $recipe['ingredients']);
        self::assertSame('Simmer for hours.', $recipe['instructions']);
        self::assertSame($this->ownerId, $recipe['user_id']);
    }

    public function testFindByIdForUserReturnsNullForAnotherUsersRecipe(): void
    {
        $id = $this->repository->create($this->ownerId, 'Private', 'x', 'y');

        self::assertNull($this->repository->findByIdForUser($id, $this->otherUserId));
    }

    public function testFindByIdForUserReturnsNullForANonexistentId(): void
    {
        self::assertNull($this->repository->findByIdForUser(999999, $this->ownerId));
    }

    public function testListForUserOnlyReturnsThatUsersRecipesNewestFirst(): void
    {
        $this->repository->create($this->ownerId, 'First', '', '');
        $this->repository->create($this->otherUserId, 'Not mine', '', '');
        $this->repository->create($this->ownerId, 'Second', '', '');

        $titles = array_column($this->repository->listForUser($this->ownerId), 'title');

        self::assertSame(['Second', 'First'], $titles);
    }

    public function testUpdateChangesTheRecipeWhenOwnedByTheCaller(): void
    {
        $id = $this->repository->create($this->ownerId, 'Original', 'a', 'b');

        $this->repository->update($id, $this->ownerId, 'Revised', 'c', 'd');

        $recipe = $this->repository->findByIdForUser($id, $this->ownerId);
        self::assertNotNull($recipe);
        self::assertSame('Revised', $recipe['title']);
        self::assertSame('c', $recipe['ingredients']);
        self::assertSame('d', $recipe['instructions']);
    }

    public function testUpdateIsANoOpWhenTheCallerDoesNotOwnTheRecipe(): void
    {
        $id = $this->repository->create($this->ownerId, 'Original', 'a', 'b');

        $this->repository->update($id, $this->otherUserId, 'Hijacked', 'x', 'y');

        $recipe = $this->repository->findByIdForUser($id, $this->ownerId);
        self::assertNotNull($recipe);
        self::assertSame('Original', $recipe['title']);
    }

    public function testDeleteRemovesTheRecipeWhenOwnedByTheCaller(): void
    {
        $id = $this->repository->create($this->ownerId, 'Gone soon', '', '');

        $this->repository->delete($id, $this->ownerId);

        self::assertNull($this->repository->findByIdForUser($id, $this->ownerId));
    }

    public function testDeleteIsANoOpWhenTheCallerDoesNotOwnTheRecipe(): void
    {
        $id = $this->repository->create($this->ownerId, 'Safe', '', '');

        $this->repository->delete($id, $this->otherUserId);

        self::assertNotNull($this->repository->findByIdForUser($id, $this->ownerId));
    }
}
