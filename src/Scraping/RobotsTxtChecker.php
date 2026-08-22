<?php

declare(strict_types=1);

namespace App\Scraping;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Good-enough (not full-RFC-9309) robots.txt compliance for recipe:scrape.
 * spec.md's Open Questions left robots.txt checking explicitly undecided
 * ("accepted as low-priority risk") -- this checks anyway, since it's cheap
 * insurance and this run touches sites ranging from single-person hobby
 * blogs to legally aggressive publishers (NYT Cooking notably).
 *
 * A missing/unfetchable robots.txt is treated as "everything allowed" --
 * matching the permissive default most crawlers use, and consistent with
 * spec.md's overall low-risk-accepted stance for this one-off, non-recurring
 * scrape.
 */
final class RobotsTxtChecker
{
    /** @var array<string, string|null> host => raw robots.txt content, or null if unfetchable */
    private array $cache = [];

    public function __construct(private readonly ClientInterface $http)
    {
    }

    /**
     * Fetches (and caches, per host, for the lifetime of this instance --
     * i.e. one recipe:scrape run) /robots.txt for the given URL's host, then
     * checks whether $userAgent may fetch $url's path.
     */
    public function isAllowedForUrl(string $userAgent, string $url): bool
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? null;

        if ($host === null) {
            // Unparseable URL -- not this checker's job to reject it, let
            // the caller's own request attempt surface that.
            return true;
        }

        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        if (!array_key_exists($host, $this->cache)) {
            $this->cache[$host] = $this->fetch($scheme . '://' . $host . '/robots.txt');
        }

        $robotsTxt = $this->cache[$host];

        if ($robotsTxt === null) {
            return true;
        }

        return self::isAllowed($robotsTxt, $userAgent, $path);
    }

    private function fetch(string $robotsUrl): ?string
    {
        try {
            $response = $this->http->request('GET', $robotsUrl, ['http_errors' => false]);
        } catch (GuzzleException) {
            return null;
        }

        if ($response->getStatusCode() >= 400) {
            return null;
        }

        return (string) $response->getBody();
    }

    /**
     * Pure parse+match, no I/O -- kept separate from fetch() so it's
     * trivially unit-testable against hand-written robots.txt fixtures.
     *
     * Groups robots.txt into User-agent blocks, picks the most specific
     * matching group for $userAgent (falling back to "*"), then finds the
     * longest matching Disallow/Allow rule within that group -- longest
     * match wins; an equal-length Allow beats a Disallow (the common
     * real-world convention, e.g. Google's).
     */
    public static function isAllowed(string $robotsTxtContent, string $userAgent, string $path): bool
    {
        $groups = self::parseGroups($robotsTxtContent);

        $rules = $groups[strtolower($userAgent)] ?? $groups['*'] ?? [];

        $bestLength = -1;
        $bestAllow = true;

        foreach ($rules as [$directive, $prefix]) {
            if ($prefix === '' || !str_starts_with($path, $prefix)) {
                continue;
            }

            $length = strlen($prefix);

            if ($length > $bestLength || ($length === $bestLength && $directive === 'allow')) {
                $bestLength = $length;
                $bestAllow = $directive === 'allow';
            }
        }

        return $bestAllow;
    }

    /**
     * @return array<string, list<array{0: 'allow'|'disallow', 1: string}>> lowercased user-agent => ordered [directive, path-prefix] pairs
     */
    private static function parseGroups(string $content): array
    {
        $groups = [];
        $currentAgents = [];
        // Per the robots.txt convention: consecutive User-agent lines share
        // one group; a User-agent line seen right after a rule line starts
        // a fresh group instead of extending the previous one.
        $lastLineWasUserAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'user-agent') {
                if (!$lastLineWasUserAgent) {
                    $currentAgents = [];
                }

                $agent = strtolower($value);
                $currentAgents[] = $agent;
                $groups[$agent] ??= [];
                $lastLineWasUserAgent = true;

                continue;
            }

            $lastLineWasUserAgent = false;

            if ($currentAgents === [] || !in_array($field, ['allow', 'disallow'], true)) {
                continue;
            }

            foreach ($currentAgents as $agent) {
                $groups[$agent][] = [$field === 'allow' ? 'allow' : 'disallow', $value];
            }
        }

        return $groups;
    }
}
