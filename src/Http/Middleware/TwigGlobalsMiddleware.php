<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionAuth;
use App\Http\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;

/**
 * Exposes per-request state to every template as Twig globals, the same way
 * slim/twig-view exposes the current request as `request`:
 *  - `current_user`: the logged-in user (or null), so the nav doesn't need
 *    every route handler to remember to pass it in.
 *  - `flash_messages`: one-shot messages queued by the previous request via
 *    App\Http\Flash, consumed (read + cleared) here.
 *  - `request`: the current request, so templates can read
 *    `request.getAttribute('csrf_name'/'csrf_value')` (set by CsrfMiddleware,
 *    which runs before this one) to render CSRF hidden fields -- see
 *    templates/partials/_csrf.twig.
 */
final class TwigGlobalsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Twig $twig)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $environment = $this->twig->getEnvironment();
        $environment->addGlobal('current_user', SessionAuth::user());
        $environment->addGlobal('flash_messages', Flash::consume());
        $environment->addGlobal('request', $request);
        $environment->addGlobal('asset_version', self::assetVersion());

        return $handler->handle($request);
    }

    /**
     * Cache-busting query string for /css/site.css and /js/site.js (both
     * templates append `?v={{ asset_version }}`). Nginx serves them with
     * only ETag/Last-Modified (no explicit Cache-Control -- see
     * docker/nginx/default.conf), which leaves a browser free to keep
     * using a stale cached copy across a normal reload without ever
     * re-checking with the server. Using the newest mtime of the two
     * forces a fresh URL (and therefore a fresh fetch) whenever either
     * file changes, with no server-side cache-header change needed.
     */
    private static function assetVersion(): string
    {
        $root = dirname(__DIR__, 3);
        $mtimes = array_filter([
            @filemtime($root . '/public/css/site.css'),
            @filemtime($root . '/public/js/site.js'),
        ], static fn ($m) => $m !== false);

        return $mtimes === [] ? '0' : (string) max($mtimes);
    }
}
