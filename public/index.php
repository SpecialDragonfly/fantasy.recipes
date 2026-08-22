<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeadersMiddleware;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = require __DIR__ . '/../src/bootstrap.php';

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addRoutingMiddleware();

(require __DIR__ . '/../src/middleware.php')($app);
(require __DIR__ . '/../src/routes.php')($app);

$settings = $container->get('settings');

$app->addErrorMiddleware((bool) $settings['app_debug'], true, true);

// Outermost layer -- see SecurityHeadersMiddleware's docblock for why this
// has to come after addErrorMiddleware() rather than living in
// src/middleware.php with the rest.
$app->add(new SecurityHeadersMiddleware());

$app->run();
