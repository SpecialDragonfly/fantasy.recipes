<?php

declare(strict_types=1);

namespace App\Http;

/**
 * General "is this request automated?" check from a User-Agent string, used
 * to flag rows in recipe_views. Broader than CrawlerAudience (which only
 * picks the /terms rendering for AI crawlers): it also catches search
 * engines, SEO/uptime tools, HTTP libraries and anything self-describing as
 * a bot. An empty User-Agent counts as a bot, since real browsers send one.
 *
 * Heuristic by nature: a bot that spoofs a browser User-Agent passes as
 * human. Good enough to separate the bulk of the noise.
 */
final class BotDetector
{
    /** @var list<string> */
    private const TOKENS = [
        'bot', 'crawl', 'spider', 'slurp', 'scrape', 'fetch', 'monitor',
        'curl/', 'wget', 'python-requests', 'python-urllib', 'aiohttp', 'httpx',
        'go-http-client', 'java/', 'okhttp', 'libwww', 'node-fetch', 'axios',
        'headlesschrome', 'phantomjs', 'puppeteer', 'playwright',
        'dataprovider', 'semrush', 'ahrefs', 'mj12', 'dotbot', 'uptimerobot',
        'pingdom', 'facebookexternalhit', 'preview', 'validator', 'lighthouse',
    ];

    public static function isBot(?string $userAgent): bool
    {
        $ua = strtolower(trim((string) $userAgent));

        if ($ua === '' || CrawlerAudience::isMachine($ua)) {
            return true;
        }

        foreach (self::TOKENS as $token) {
            if (str_contains($ua, $token)) {
                return true;
            }
        }

        return false;
    }
}
