<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RecipeRepository;
use App\Repository\StoryRepository;
use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * StoryRepository::replace() is the trickiest logic in the repository
 * layer -- it must archive the current live Story (archived_at) AND
 * install the new one as recipes.story_id, atomically.
 */
final class StoryRepositoryTest extends TestCase
{
    private PDO $pdo;
    private StoryRepository $repository;
    private RecipeRepository $recipes;
    private int $recipeId;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->recipes = new RecipeRepository($this->pdo);
        $this->recipeId = $this->recipes->create('a-recipe', 'A Recipe', null, '', 'Text.');
        $this->repository = new StoryRepository($this->pdo);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function liveStory(): ?array
    {
        $recipe = $this->recipes->findById($this->recipeId);
        self::assertNotNull($recipe);

        return $recipe['story_id'] !== null ? $this->repository->findById($recipe['story_id']) : null;
    }

    public function testCreateInstallsTheFirstLiveStoryWithNoArchiving(): void
    {
        $id = $this->repository->create($this->recipeId, 'A witch', 'Once upon a time...', null);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($id, $live['id']);
        self::assertSame('A witch', $live['narrator']);

        self::assertCount(0, $this->repository->listArchived($this->recipeId));
    }

    public function testReplaceWithNoExistingLiveStoryJustCreatesOne(): void
    {
        $newId = $this->repository->replace($this->recipeId, 'A dragon', 'A dragon tale.', null);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($newId, $live['id']);
        self::assertCount(0, $this->repository->listArchived($this->recipeId));
    }

    public function testReplaceArchivesThePreviousLiveStoryAndInstallsTheNewOne(): void
    {
        $authorUserId = (new UserRepository($this->pdo))->create('dragonauthor', 'dragon@example.com', 'password123');
        $originalId = $this->repository->create($this->recipeId, 'A witch', 'The original tale.', null);

        $newId = $this->repository->replace($this->recipeId, 'A dragon', 'The new tale.', $authorUserId);

        // New story is live.
        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($newId, $live['id']);
        self::assertSame('A dragon', $live['narrator']);
        self::assertSame('The new tale.', $live['body']);
        self::assertSame($authorUserId, $live['author_user_id']);

        // Old story still exists, but archived, not live.
        $original = $this->repository->findById($originalId);
        self::assertNotNull($original);
        self::assertNotNull($original['archived_at']);

        $archived = $this->repository->listArchived($this->recipeId);
        self::assertCount(1, $archived);
        self::assertSame('A witch', $archived[0]['narrator']);
        self::assertSame('The original tale.', $archived[0]['body']);
        self::assertNotEmpty($archived[0]['archived_at']);
    }

    public function testReplacingTwiceArchivesBothPriorStories(): void
    {
        $this->repository->create($this->recipeId, 'Narrator 1', 'Tale 1', null);
        $this->repository->replace($this->recipeId, 'Narrator 2', 'Tale 2', null);
        $this->repository->replace($this->recipeId, 'Narrator 3', 'Tale 3', null);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame('Narrator 3', $live['narrator']);

        // listArchived() orders by archived_at DESC, which only has
        // second-level resolution (Y-m-d H:i:s) -- back-to-back replace()
        // calls within the same test can tie, making DESC-among-ties
        // implementation-defined rather than guaranteed newest-first. Assert
        // both are present rather than a specific position.
        $archived = $this->repository->listArchived($this->recipeId);
        self::assertCount(2, $archived);
        self::assertEqualsCanonicalizing(
            ['Narrator 1', 'Narrator 2'],
            array_column($archived, 'narrator'),
        );
    }

    public function testUpdateChangesTheLiveStoryInPlaceWithNoArchiving(): void
    {
        $authorUserId = (new UserRepository($this->pdo))->create('witchauthor', 'witch@example.com', 'password123');
        $id = $this->repository->create($this->recipeId, 'A witch', 'Once uopn a time...', $authorUserId);

        $this->repository->update($id, 'A witch', 'Once upon a time...');

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($id, $live['id']);
        self::assertSame('Once upon a time...', $live['body']);
        // Authorship is untouched by a plain correction.
        self::assertSame($authorUserId, $live['author_user_id']);
        self::assertCount(0, $this->repository->listArchived($this->recipeId));
    }

    public function testUpdateCanChangeTheNarratorToo(): void
    {
        $id = $this->repository->create($this->recipeId, 'A witch', 'A tale.', null);

        $this->repository->update($id, 'A dragon', 'A tale.');

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame('A dragon', $live['narrator']);
    }

    public function testReplaceRollsBackTheArchiveAndPointerWhenTheFinalInsertFails(): void
    {
        $this->repository->create($this->recipeId, 'A witch', 'The original tale.', null);

        // A bogus author_user_id makes the final INSERT violate the
        // stories.author_user_id FK -- but only *after* replace() has
        // already archived the current live story within the same
        // transaction. Confirms a failure here doesn't leave the recipe
        // with no live story and a spuriously archived one.
        $this->expectException(\PDOException::class);
        try {
            $this->repository->replace($this->recipeId, 'Someone', 'Something', 999999);
        } finally {
            $live = $this->liveStory();
            self::assertNotNull($live);
            self::assertSame('A witch', $live['narrator']);
            self::assertNull($live['archived_at']);
            self::assertCount(0, $this->repository->listArchived($this->recipeId));
        }
    }
}
