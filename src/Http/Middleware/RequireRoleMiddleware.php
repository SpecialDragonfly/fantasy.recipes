<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Roles;
use App\Auth\SessionAuth;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Route-group authorization middleware -- e.g.
 * `$app->group('/admin', ...)->add(new RequireRoleMiddleware(Roles::ADMIN))`.
 * Matches the flat three-tier model in spec.md -- Roles & Permissions: no
 * per-resource ACLs, just "does this session's role rank high enough".
 */
final class RequireRoleMiddleware implements MiddlewareInterface
{
    private readonly ResponseFactory $responseFactory;

    public function __construct(private readonly string $minimumRole)
    {
        $this->responseFactory = new ResponseFactory();
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (Roles::atLeast(SessionAuth::role(), $this->minimumRole)) {
            return $handler->handle($request);
        }

        if (!SessionAuth::isLoggedIn()) {
            $next = urlencode((string) $request->getUri()->getPath());

            return $this->responseFactory->createResponse(302)
                ->withHeader('Location', '/login?next=' . $next);
        }

        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write('403 Forbidden -- your account does not have access to this page.');

        return $response;
    }
}
