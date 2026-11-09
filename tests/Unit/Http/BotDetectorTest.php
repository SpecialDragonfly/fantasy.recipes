<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\BotDetector;
use PHPUnit\Framework\TestCase;

final class BotDetectorTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function userAgents(): iterable
    {
        yield 'Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36', false];
        yield 'iPhone Safari' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1', false];
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true];
        yield 'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', true];
        yield 'GPTBot' => ['Mozilla/5.0 (compatible; GPTBot/1.2)', true];
        yield 'curl' => ['curl/8.5.0', true];
        yield 'python-requests' => ['python-requests/2.31.0', true];
        yield 'Dataprovider' => ['Mozilla/5.0 (compatible; Dataprovider.com)', true];
        yield 'headless Chrome' => ['Mozilla/5.0 HeadlessChrome/120.0 Safari/537.36', true];
        yield 'empty' => ['', true];
        yield 'null' => [null, true];
    }

    /**
     * @dataProvider userAgents
     */
    public function testClassifiesUserAgents(?string $userAgent, bool $expected): void
    {
        self::assertSame($expected, BotDetector::isBot($userAgent));
    }
}
