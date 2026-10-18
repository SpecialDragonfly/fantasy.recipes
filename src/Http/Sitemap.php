<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;

/**
 * Builds the sitemaps.org XML for /sitemap.xml.
 *
 * Pure: takes the origin and the published-recipe rows, returns a string.
 * The route feeds it live data, which is why there's no file to keep in
 * step with publish/unpublish.
 */
final class Sitemap
{
    /** Static, indexable pages. Must match the `index,follow` templates. */
    private const PAGES = ['/', '/recipes', '/tags', '/writers', '/terms'];

    /**
     * @param list<array{slug: string, updated_at: string}> $recipes
     */
    public static function build(string $appUrl, array $recipes): string
    {
        $origin = rtrim($appUrl, '/');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach (self::PAGES as $path) {
            $xml .= self::url($origin . $path, null);
        }

        foreach ($recipes as $recipe) {
            $xml .= self::url($origin . '/recipes/' . rawurlencode($recipe['slug']), $recipe['updated_at']);
        }

        return $xml . '</urlset>' . "\n";
    }

    private static function url(string $loc, ?string $updatedAt): string
    {
        $entry = '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';

        if ($updatedAt !== null) {
            $entry .= '<lastmod>' . (new DateTimeImmutable($updatedAt))->format('Y-m-d') . '</lastmod>';
        }

        return $entry . "</url>\n";
    }
}
