<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Builds the <meta name="description"> text for a page: plain text, whitespace
 * collapsed, cut at a word boundary to fit a search-result snippet (~155
 * characters). Escaping is left to Twig.
 */
final class MetaDescription
{
    private const MAX_LENGTH = 155;

    public static function fromText(string $text, int $max = self::MAX_LENGTH): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        if (mb_strlen($flat) <= $max) {
            return $flat;
        }

        $cut = mb_substr($flat, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) ($max / 2)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:.-") . '…';
    }
}
