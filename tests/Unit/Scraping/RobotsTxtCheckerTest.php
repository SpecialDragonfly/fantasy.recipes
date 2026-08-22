<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scraping;

use App\Scraping\RobotsTxtChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * RobotsTxtChecker::isAllowed() is a pure static method (no I/O) -- tested
 * directly against hand-written robots.txt text, no network involved. A
 * couple of tests also exercise isAllowedForUrl() via a MockHandler-backed
 * Guzzle client to confirm the fetch+cache wrapper behaves, still with zero
 * real HTTP traffic.
 */
final class RobotsTxtCheckerTest extends TestCase
{
    public function testAllowsEverythingWhenNoRulesMatch(): void
    {
        $robots = "User-agent: *\nDisallow: /private/";

        self::assertTrue(RobotsTxtChecker::isAllowed($robots, 'fantasy.recipes-scraper', '/recipes/stew'));
    }

    public function testDisallowedPathIsBlocked(): void
    {
        $robots = "User-agent: *\nDisallow: /private/";

        self::assertFalse(RobotsTxtChecker::isAllowed($robots, 'fantasy.recipes-scraper', '/private/secret'));
    }

    public function testSpecificUserAgentGroupTakesPrecedenceOverWildcard(): void
    {
        $robots = "User-agent: *\nDisallow: /recipes/\n\nUser-agent: fantasy.recipes-scraper\nDisallow:\n";

        // Wildcard group would block this, but the specific group (empty
        // Disallow == allow everything) should win.
        self::assertTrue(RobotsTxtChecker::isAllowed($robots, 'fantasy.recipes-scraper', '/recipes/stew'));
        // A different agent still gets the wildcard rule.
        self::assertFalse(RobotsTxtChecker::isAllowed($robots, 'some-other-bot', '/recipes/stew'));
    }

    public function testLongestMatchingRuleWins(): void
    {
        $robots = "User-agent: *\nDisallow: /recipes/\nAllow: /recipes/public/";

        self::assertFalse(RobotsTxtChecker::isAllowed($robots, '*', '/recipes/secret'));
        self::assertTrue(RobotsTxtChecker::isAllowed($robots, '*', '/recipes/public/stew'));
    }

    public function testConsecutiveUserAgentLinesShareOneGroup(): void
    {
        $robots = "User-agent: bot-a\nUser-agent: bot-b\nDisallow: /no-bots/\n";

        self::assertFalse(RobotsTxtChecker::isAllowed($robots, 'bot-a', '/no-bots/x'));
        self::assertFalse(RobotsTxtChecker::isAllowed($robots, 'bot-b', '/no-bots/x'));
    }

    public function testUserAgentLineAfterRulesStartsANewGroup(): void
    {
        $robots = "User-agent: bot-a\nDisallow: /a-only/\n\nUser-agent: bot-b\nDisallow: /b-only/\n";

        self::assertTrue(RobotsTxtChecker::isAllowed($robots, 'bot-a', '/b-only/x'));
        self::assertFalse(RobotsTxtChecker::isAllowed($robots, 'bot-a', '/a-only/x'));
        self::assertTrue(RobotsTxtChecker::isAllowed($robots, 'bot-b', '/a-only/x'));
        self::assertFalse(RobotsTxtChecker::isAllowed($robots, 'bot-b', '/b-only/x'));
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        $robots = "# this is a comment\nUser-agent: *\n\n# another comment\nDisallow: /secret/ # trailing comment\n";

        self::assertFalse(RobotsTxtChecker::isAllowed($robots, '*', '/secret/x'));
        self::assertTrue(RobotsTxtChecker::isAllowed($robots, '*', '/public/x'));
    }

    public function testIsAllowedForUrlFetchesAndCachesPerHost(): void
    {
        $mock = new MockHandler([
            new Response(200, [], "User-agent: *\nDisallow: /blocked/"),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new RobotsTxtChecker($client);

        // Two calls to the same host -- only one HTTP fetch should happen
        // (MockHandler would throw "no more responses" on a second request).
        self::assertFalse($checker->isAllowedForUrl('*', 'https://example.com/blocked/page'));
        self::assertTrue($checker->isAllowedForUrl('*', 'https://example.com/open/page'));
    }

    public function testMissingRobotsTxtAllowsEverything(): void
    {
        $mock = new MockHandler([new Response(404)]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new RobotsTxtChecker($client);

        self::assertTrue($checker->isAllowedForUrl('*', 'https://example.com/anything'));
    }
}
