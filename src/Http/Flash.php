<?php

declare(strict_types=1);

namespace App\Http;

/**
 * One-shot flash messages for the redirect-after-POST pattern (e.g. "Account
 * created", "Story shouted into the Void" -- see spec.md -- Immersion
 * Rules). Stored in $_SESSION, consumed (read + cleared) once by
 * TwigGlobalsMiddleware on the next request.
 */
final class Flash
{
    public static function add(string $type, string $text): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'text' => $text];
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    public static function consume(): array
    {
        /** @var list<array{type: string, text: string}> $messages */
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $messages;
    }
}
