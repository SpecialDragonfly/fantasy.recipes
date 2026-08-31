<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\CrawlerAudience;
use PHPUnit\Framework\TestCase;

/**
 * CrawlerAudience picks which /terms rendering a request gets. The contract
 * that matters: known AI-training / answer-engine crawlers get MACHINE;
 * people, ordinary search crawlers, and anything unrecognised get HUMAN
 * (the human page still carries the actual terms, so it's the safe
 * default).
 */
final class CrawlerAudienceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function aiCrawlerUserAgents(): iterable
    {
        yield 'GPTBot' => ['Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'];
        yield 'ClaudeBot' => ['Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'];
        yield 'anthropic-ai' => ['anthropic-ai'];
        yield 'CCBot' => ['CCBot/2.0 (https://commoncrawl.org/faq/)'];
        yield 'Google-Extended' => ['Mozilla/5.0 (compatible; Google-Extended/1.0)'];
        yield 'PerplexityBot' => ['Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/bot)'];
        yield 'Bytespider' => ['Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)'];
        yield 'Amazonbot' => ['Mozilla/5.0 (compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)'];
        yield 'meta-externalagent' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)'];
        yield 'case-insensitive' => ['SOMETHING-GPTBOT-CLONE'];
    }

    /**
     * @dataProvider aiCrawlerUserAgents
     */
    public function testKnownAiCrawlersGetTheMachineVersion(string $userAgent): void
    {
        self::assertSame(CrawlerAudience::MACHINE, CrawlerAudience::fromUserAgent($userAgent));
        self::assertTrue(CrawlerAudience::isMachine($userAgent));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function humanUserAgents(): iterable
    {
        yield 'Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'];
        yield 'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15'];
        yield 'Googlebot (ordinary search)' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'curl' => ['curl/8.4.0'];
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
        yield 'null' => [null];
    }

    /**
     * @dataProvider humanUserAgents
     */
    public function testEverythingElseGetsTheHumanVersion(?string $userAgent): void
    {
        self::assertSame(CrawlerAudience::HUMAN, CrawlerAudience::fromUserAgent($userAgent));
        self::assertFalse(CrawlerAudience::isMachine($userAgent));
    }
}
