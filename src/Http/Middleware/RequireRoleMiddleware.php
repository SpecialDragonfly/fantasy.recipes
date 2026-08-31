<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Roles;
use App\Auth\SessionAuth;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Route-group authorization middleware -- e.g.
 * `$app->group('/admin', ...)->add(new RequireRoleMiddleware(Roles::ADMIN, $userRepository))`.
 * Matches the flat three-tier model in spec.md -- Roles & Permissions: no
 * per-resource ACLs, just "does this session's role rank high enough".
 *
 * Also the one place that catches a stale session: SessionAuth reads the
 * logged-in user straight out of $_SESSION and never re-checks it against
 * the users table on its own (see SessionAuth's docblock), so a session
 * that outlives its user -- the account got deleted, or the DB was
 * reset/reseeded, while a browser was still logged in -- keeps handing
 * back an id nothing in the database actually has. Every protected route
 * goes through this middleware, and several of them pass SessionAuth::id()
 * straight into an INSERT as a real FOREIGN KEY (grimoire_entries,
 * personal_recipes, stories.author_user_id) -- so a stale session used to
 * surface as a confusing PDOException (FOREIGN KEY constraint failed) deep
 * in a repository instead of the honest "you're not logged in anymore"
 * this now produces.
 */
final class RequireRoleMiddleware implements MiddlewareInterface
{
    private readonly ResponseFactory $responseFactory;

    public function __construct(
        private readonly string $minimumRole,
        private readonly UserRepository $users,
    ) {
        $this->responseFactory = new ResponseFactory();
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (SessionAuth::isLoggedIn() && $this->users->findById((int) SessionAuth::id()) === null) {
            SessionAuth::logout();
        }

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
