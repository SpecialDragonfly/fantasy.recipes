<?php

declare(strict_types=1);

use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\SessionStartMiddleware;
use App\Http\Middleware\TwigGlobalsMiddleware;
use Slim\App;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering middleware.');
    }

    // Slim executes added middleware last-in-first-out, so this list reads
    // innermost (closest to route handlers) first, outermost (closest to
    // the raw request) last. Desired request-time order is:
    //   SessionStart -> Csrf -> TwigGlobals -> Twig -> route
    // because Csrf's token storage needs an active session, and the Twig
    // globals it and TwigGlobalsMiddleware set need to be in place before
    // any route handler calls $view->render().
    // TwigMiddleware::createFromContainer() never attaches a request
    // attribute (that's only done by ::create()), so it's incompatible
    // with Twig::fromRequest() used throughout the route files -- use
    // ::create() instead, which defaults the attribute name to "view".
    $app->add(TwigMiddleware::create($app, $container->get(Twig::class)));
    $app->add(new TwigGlobalsMiddleware($container->get(Twig::class)));

    // slim/csrf stores its token pair in $_SESSION by default, which is why
    // SessionStartMiddleware is added after (= runs before) this one.
    $app->add(new CsrfMiddleware($app->getResponseFactory()));

    $app->add(new SessionStartMiddleware(dirname(__DIR__) . '/storage/sessions'));
};
