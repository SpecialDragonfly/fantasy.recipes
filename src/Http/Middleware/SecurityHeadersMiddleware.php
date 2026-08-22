<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * `X-Frame-Options: SAMEORIGIN` on every response -- refuses to let this
 * site be framed by another origin (clickjacking protection), the same
 * header BBC Good Food and Riverford send that leaves their own iframe
 * preview blank in this app's admin "Import a recipe" manual-fallback page
 * (see templates/admin/recipe_import_manual.twig, App\Scraping\RecipeImporter).
 * SAMEORIGIN rather than DENY -- there's no known legitimate same-origin
 * framing need today, but nothing rules one out either, and SAMEORIGIN
 * still blocks the actual threat (another site framing this one).
 *
 * Registered directly in public/index.php *after* addErrorMiddleware(),
 * not in src/middleware.php with everything else -- Slim's middleware
 * stack is LIFO (last added = outermost), so this needs to be the true
 * outermost layer for the header to land on every response, including one
 * built by the error middleware itself for an unhandled exception (an
 * exception thrown deeper in the stack unwinds past any inner
 * middleware's `return`, skipping whatever it would otherwise have added
 * to the response).
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        return $handler->handle($request)->withHeader('X-Frame-Options', 'SAMEORIGIN');
    }
}
