<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Decides, from a request's User-Agent string, whether the visitor reading
 * /terms is a person or an automated crawler -- specifically the kind of
 * crawler that ingests pages to train or feed a language model / answer
 * engine.
 *
 * The /terms route uses this to serve one of two versions of the same
 * document (templates/terms/index.twig for people, templates/terms/machine.twig
 * for bots). The bot version says the same thing the human one does, at
 * far greater length and with every term defined, because that is the
 * audience that is going to have the document parsed rather than read.
 *
 * Matching is a case-insensitive substring test against a fixed list of
 * known AI-crawler tokens. This is deliberately not a general "is this a
 * bot" check -- ordinary search-engine crawlers (Googlebot, bingbot) and
 * uptime monitors get the human page, same as a browser. Only agents on
 * the list below get MACHINE. An empty or absent User-Agent is treated as
 * HUMAN: browsers that suppress it exist, and the human page is the safe
 * default (it still contains the actual terms).
 */
final class CrawlerAudience
{
    public const HUMAN = 'human';
    public const MACHINE = 'machine';

    /**
     * Tokens that appear in the User-Agent of crawlers operated to collect
     * text for LLM training or for retrieval-augmented answer engines.
     * Lower-cased here; the check lower-cases the incoming header too.
     * Sourced from the operators' own published crawler documentation.
     *
     * @var list<string>
     */
    private const AI_CRAWLER_TOKENS = [
        'gptbot',
        'oai-searchbot',
        'chatgpt-user',
        'claudebot',
        'claude-web',
        'anthropic-ai',
        'ccbot',
        'google-extended',
        'googleother',
        'perplexitybot',
        'perplexity-user',
        'bytespider',
        'amazonbot',
        'applebot-extended',
        'meta-externalagent',
        'meta-externalfetcher',
        'facebookbot',
        'cohere-ai',
        'cohere-training-data-crawler',
        'diffbot',
        'omgili',
        'omgilibot',
        'imagesiftbot',
        'youbot',
        'timpibot',
        'webzio-extended',
        'petalbot',
        'ai2bot',
        'friendlycrawler',
        'scrapy',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent = strtolower(trim((string) $userAgent));

        if ($userAgent === '') {
            return self::HUMAN;
        }

        foreach (self::AI_CRAWLER_TOKENS as $token) {
            if (str_contains($userAgent, $token)) {
                return self::MACHINE;
            }
        }

        return self::HUMAN;
    }

    public static function isMachine(?string $userAgent): bool
    {
        return self::fromUserAgent($userAgent) === self::MACHINE;
    }
}
