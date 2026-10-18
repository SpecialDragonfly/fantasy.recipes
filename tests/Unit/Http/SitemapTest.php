<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Sitemap;
use App\Repository\RecipeRepository;
use App\Tests\Support\InMemoryDatabase;
use PHPUnit\Framework\TestCase;

final class SitemapTest extends TestCase
{
    public function testListsStaticPagesAndEachRecipeWithLastmod(): void
    {
        $xml = Sitemap::build('https://example.test/', [
            ['slug' => 'dragons-stew', 'updated_at' => '2026-03-04 10:00:00'],
        ]);

        self::assertStringContainsString('<loc>https://example.test/</loc>', $xml);
        self::assertStringContainsString('<loc>https://example.test/recipes</loc>', $xml);
        self::assertStringContainsString(
            '<loc>https://example.test/recipes/dragons-stew</loc><lastmod>2026-03-04</lastmod>',
            $xml,
        );
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testEscapesXmlSpecialCharacters(): void
    {
        $xml = Sitemap::build('https://example.test', [
            ['slug' => 'a&b', 'updated_at' => '2026-03-04 10:00:00'],
        ]);

        self::assertStringContainsString('/recipes/a%26b</loc>', $xml);
        self::assertNotFalse(simplexml_load_string($xml));
    }

    public function testOnlyPublishedRecipesAppearAndUnpublishingRemovesThem(): void
    {
        $repository = new RecipeRepository(InMemoryDatabase::create());
        $live = $repository->create('live-stew', 'Live Stew', null, '', '');
        $repository->create('draft-stew', 'Draft Stew', null, '', '');
        $repository->setPublished($live, true);

        $build = static fn (): string => Sitemap::build('https://example.test', $repository->listPublishedForSitemap());

        self::assertStringContainsString('/recipes/live-stew', $build());
        self::assertStringNotContainsString('draft-stew', $build());

        $repository->setPublished($live, false);

        self::assertStringNotContainsString('live-stew', $build());
    }
}
