<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use App\Search\LikeSearch;
use App\Tests\Support\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class LikeSearchTest extends TestCase
{
    private PDO $pdo;
    private LikeSearch $search;

    protected function setUp(): void
    {
        $this->pdo = InMemoryDatabase::create();
        $this->search = new LikeSearch($this->pdo);
    }

    public function testMundaneAndRitualTermsBothMatchTheSameRecipe(): void
    {
        $recipeId = InMemoryDatabase::seedRecipe(
            $this->pdo,
            'beef-stew',
            true,
            'Simmer the beef with onions.',
            'Simmer the dragonflesh with moon-onions.',
        );

        $mundaneResults = $this->search->search('beef');
        $ritualResults = $this->search->search('dragonflesh');

        self::assertCount(1, $mundaneResults);
        self::assertSame($recipeId, $mundaneResults[0]['id']);
        self::assertCount(1, $ritualResults);
        self::assertSame($recipeId, $ritualResults[0]['id']);
    }

    public function testUnpublishedRecipesNeverSurface(): void
    {
        InMemoryDatabase::seedRecipe(
            $this->pdo,
            'unpublished',
            false,
            'Simmer the beef.',
            'Simmer the dragonflesh.',
        );

        self::assertSame([], $this->search->search('beef'));
    }

    public function testNoMatchReturnsEmptyArray(): void
    {
        InMemoryDatabase::seedRecipe($this->pdo);

        self::assertSame([], $this->search->search('nonexistentingredient'));
    }

    public function testEmptyQueryDoesNotErrorAndMatchesEverythingPublished(): void
    {
        InMemoryDatabase::seedRecipe($this->pdo);

        // An empty query becomes a LIKE '%%' pattern -- a legitimate "match
        // everything published" result, not an error.
        self::assertCount(1, $this->search->search(''));
    }

    public function testPercentAndUnderscoreInQueryAreTreatedLiterallyNotAsWildcards(): void
    {
        InMemoryDatabase::seedRecipe($this->pdo);

        self::assertSame([], $this->search->search('100%_wrong'));
    }
}
