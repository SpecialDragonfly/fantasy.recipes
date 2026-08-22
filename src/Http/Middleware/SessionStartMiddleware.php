<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Native PHP sessions, file-backed on a path that lives on a persisted
 * Docker volume so a container restart doesn't drop active sessions -- see
 * architecture.md -- Application Architecture (Sessions).
 *
 * Must run before Slim\Csrf\Guard, which stores its token pair in
 * $_SESSION -- see src/middleware.php for the required add() order.
 */
final class SessionStartMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $savePath)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!is_dir($this->savePath)) {
                mkdir($this->savePath, 0775, true);
            }

            session_save_path($this->savePath);

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => self::isHttps($request),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_name('fantasy_recipes_session');
            session_start();
        }

        return $handler->handle($request);
    }

    /**
     * There's a proxy in front of a proxy (host nginx -> app nginx ->
     * php-fpm) -- see architecture.md -- Deployment & Networking (Trusted
     * proxy headers). Trust X-Forwarded-Proto for the secure-cookie
     * decision rather than the raw request scheme, which would otherwise
     * always look like plain HTTP from php-fpm's point of view.
     */
    private static function isHttps(Request $request): bool
    {
        $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');

        if ($forwardedProto !== '') {
            return strtolower($forwardedProto) === 'https';
        }

        return $request->getUri()->getScheme() === 'https';
    }
}
