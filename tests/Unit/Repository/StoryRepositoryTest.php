<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\RecipeRepository;
use App\Repository\StoryRepository;
use App\Repository\UserRepository;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PDOException;
use PDOStatement;
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
        $id = $this->repository->create($this->recipeId, 'Once upon a time...', null);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($id, $live['id']);

        self::assertCount(0, $this->repository->listArchived($this->recipeId));
    }

    public function testReplaceWithNoExistingLiveStoryJustCreatesOne(): void
    {
        $newId = $this->repository->replace($this->recipeId, 'A dragon tale.', null);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($newId, $live['id']);
        self::assertCount(0, $this->repository->listArchived($this->recipeId));
    }

    public function testReplaceArchivesThePreviousLiveStoryAndInstallsTheNewOne(): void
    {
        $authorUserId = (new UserRepository($this->pdo))->create('dragonauthor', 'dragon@example.com', 'password123');
        $originalId = $this->repository->create($this->recipeId, 'The original tale.', null);

        $newId = $this->repository->replace($this->recipeId, 'The new tale.', $authorUserId);

        // New story is live.
        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($newId, $live['id']);
        self::assertSame('The new tale.', $live['body']);
        self::assertSame($authorUserId, $live['author_user_id']);

        // Old story still exists, but archived, not live.
        $original = $this->repository->findById($originalId);
        self::assertNotNull($original);
        self::assertNotNull($original['archived_at']);

        $archived = $this->repository->listArchived($this->recipeId);
        self::assertCount(1, $archived);
        self::assertSame('The original tale.', $archived[0]['body']);
        self::assertNotEmpty($archived[0]['archived_at']);
    }

    public function testReplacingTwiceArchivesBothPriorStories(): void
    {
        $this->repository->create($this->recipeId, 'Tale 1', null);
        $this->repository->replace($this->recipeId, 'Tale 2', null);
        $this->repository->replace($this->recipeId, 'Tale 3', null);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame('Tale 3', $live['body']);

        // listArchived() orders by archived_at DESC, which only has
        // second-level resolution (Y-m-d H:i:s) -- back-to-back replace()
        // calls within the same test can tie, making DESC-among-ties
        // implementation-defined rather than guaranteed newest-first. Assert
        // both are present rather than a specific position.
        $archived = $this->repository->listArchived($this->recipeId);
        self::assertCount(2, $archived);
        self::assertEqualsCanonicalizing(
            ['Tale 1', 'Tale 2'],
            array_column($archived, 'body'),
        );
    }

    public function testUpdateChangesTheLiveStoryInPlaceWithNoArchiving(): void
    {
        $authorUserId = (new UserRepository($this->pdo))->create('witchauthor', 'witch@example.com', 'password123');
        $id = $this->repository->create($this->recipeId, 'Once uopn a time...', $authorUserId);

        $this->repository->update($id, 'Once upon a time...');

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($id, $live['id']);
        self::assertSame('Once upon a time...', $live['body']);
        // Authorship is untouched by a plain correction.
        self::assertSame($authorUserId, $live['author_user_id']);
        self::assertCount(0, $this->repository->listArchived($this->recipeId));
    }

    /**
     * A nonexistent author id no longer makes create() blow up -- see
     * testCreateFallsBackToNoAuthorWhenTheGivenAuthorIdDoesNotExist() below
     * -- so this test injects a real failure via a PDO subclass instead of
     * relying incidentally on the author_user_id FK the way it used to.
     */
    public function testReplaceRollsBackTheArchiveAndPointerWhenTheFinalInsertFails(): void
    {
        $failingPdo = new InsertIntoStoriesFailsPdo('sqlite::memory:');
        $pdo = InMemoryDatabase::create($failingPdo);
        $recipes = new RecipeRepository($pdo);
        $recipeId = $recipes->create('a-recipe', 'A Recipe', null, '', 'Text.');
        $repository = new StoryRepository($pdo);

        $repository->create($recipeId, 'The original tale.', null);

        // Only start failing INSERT INTO stories *after* the create() call
        // above, which needs to succeed to give replace() something live
        // to archive -- replace()'s own INSERT is the one under test.
        $failingPdo->failNextStoriesInsert = true;

        // Confirms a failure on the final INSERT (after replace() has
        // already archived the current live story within the same
        // transaction) doesn't leave the recipe with no live story and a
        // spuriously archived one.
        $this->expectException(PDOException::class);
        try {
            $repository->replace($recipeId, 'Something', null);
        } finally {
            $recipe = $recipes->findById($recipeId);
            self::assertNotNull($recipe);
            $live = $recipe['story_id'] !== null ? $repository->findById((int) $recipe['story_id']) : null;
            self::assertNotNull($live);
            self::assertSame('The original tale.', $live['body']);
            self::assertNull($live['archived_at']);
            self::assertCount(0, $repository->listArchived($recipeId));
        }
    }

    public function testCreateFallsBackToNoAuthorWhenTheGivenAuthorIdDoesNotExist(): void
    {
        // Reproduces the bug this guards against: SessionAuth::id() is read
        // straight out of $_SESSION and never re-checked against the users
        // table, so a session that outlives its user (account deleted, or
        // the DB reset/reseeded, while still logged in) used to crash the
        // save entirely with a FOREIGN KEY constraint violation instead of
        // just landing with no attributed author.
        $id = $this->repository->create($this->recipeId, 'A tale with a ghost author.', 999999);

        $live = $this->liveStory();
        self::assertNotNull($live);
        self::assertSame($id, $live['id']);
        self::assertNull($live['author_user_id']);
    }

}

/**
 * Throws on the specific INSERT INTO stories statement once armed
 * (failNextStoriesInsert), everything else passed straight through to a
 * real sqlite::memory: connection -- lets
 * testReplaceRollsBackTheArchiveAndPointerWhenTheFinalInsertFails() force a
 * failure at exactly the point it needs to, without depending on an
 * incidental constraint violation the production code is now deliberately
 * resilient to. Starts disarmed so setup code (e.g. a create() call to
 * seed a live Story to archive) can insert normally first.
 */
final class InsertIntoStoriesFailsPdo extends PDO
{
    public bool $failNextStoriesInsert = false;

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failNextStoriesInsert && str_starts_with($query, 'INSERT INTO stories')) {
            throw new PDOException('Simulated failure for test.');
        }

        return parent::prepare($query, $options);
    }
}
