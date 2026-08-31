<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Csrf\Guard;

/**
 * Thin wrapper around slim/csrf's Guard that defers construction to
 * process() time. Guard's constructor reads $_SESSION eagerly, but Guard
 * itself is built once when middleware.php runs (app setup), before any
 * given request's SessionStartMiddleware has had a chance to call
 * session_start() -- constructing it here instead, per request, after
 * SessionStartMiddleware has already run, avoids that ordering problem.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        // RFC 8058 one-click unsubscribe: a POST to /unsubscribe comes from
        // the recipient's mail provider (Gmail etc.), triggered by the
        // List-Unsubscribe-Post header -- there is no session and no CSRF
        // token to present. Exempt exactly that one path+method; the
        // /unsubscribe GET link and the /unsubscribe/resubscribe form (a
        // real page on our site) still go through the Guard.
        if ($request->getMethod() === 'POST' && $request->getUri()->getPath() === '/unsubscribe') {
            return $handler->handle($request);
        }

        $guard = new Guard($this->responseFactory);

        return $guard->process($request, $handler);
    }
}
