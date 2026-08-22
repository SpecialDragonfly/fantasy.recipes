<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\GrimoireRepository;
use App\Repository\RecipeRepository;
use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class GrimoireRepositoryTest extends TestCase
{
    private PDO $pdo;
    private GrimoireRepository $repository;
    private int $userId;
    private int $recipeId;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->userId = (new UserRepository($this->pdo))->create('alice', 'alice@example.com', 'password123');
        $this->recipeId = (new RecipeRepository($this->pdo))->create('a-recipe', 'A Recipe', null, '', '');
        $this->repository = new GrimoireRepository($this->pdo);
    }

    public function testIsInGrimoireStartsFalse(): void
    {
        self::assertFalse($this->repository->isInGrimoire($this->userId, $this->recipeId));
    }

    public function testAddThenIsInGrimoireIsTrueAndAppearsInList(): void
    {
        $this->repository->add($this->userId, $this->recipeId);

        self::assertTrue($this->repository->isInGrimoire($this->userId, $this->recipeId));

        $list = $this->repository->listForUser($this->userId);
        self::assertCount(1, $list);
        self::assertSame($this->recipeId, $list[0]['recipe_id']);
        self::assertSame('a-recipe', $list[0]['recipe_slug']);
    }

    public function testAddIsIdempotent(): void
    {
        $this->repository->add($this->userId, $this->recipeId);
        $this->repository->add($this->userId, $this->recipeId);

        self::assertCount(1, $this->repository->listForUser($this->userId));
    }

    public function testRemoveTakesItOutOfTheList(): void
    {
        $this->repository->add($this->userId, $this->recipeId);

        $this->repository->remove($this->userId, $this->recipeId);

        self::assertFalse($this->repository->isInGrimoire($this->userId, $this->recipeId));
        self::assertCount(0, $this->repository->listForUser($this->userId));
    }

    public function testRemovingSomethingNotInTheGrimoireIsANoOp(): void
    {
        $this->repository->remove($this->userId, $this->recipeId);

        self::assertFalse($this->repository->isInGrimoire($this->userId, $this->recipeId));
    }

    public function testGrimoireIsPerUserNotShared(): void
    {
        $otherUserId = (new UserRepository($this->pdo))->create('bob', 'bob@example.com', 'password123');
        $this->repository->add($this->userId, $this->recipeId);

        self::assertFalse($this->repository->isInGrimoire($otherUserId, $this->recipeId));
        self::assertCount(0, $this->repository->listForUser($otherUserId));
    }
}
