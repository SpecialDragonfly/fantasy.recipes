<?php

declare(strict_types=1);

use App\Auth\Roles;
use App\Auth\SessionAuth;
use App\Http\Flash;
use App\Http\Middleware\RequireRoleMiddleware;
use App\Repository\GrimoireRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

// Logged-in-user routes: the Grimoire wishlist. Requires Roles::USER -- see
// spec.md -- Roles & Permissions. Story submission/moderation was removed
// entirely (no more story_submissions table -- see
// db/migrations/20260816120500_create_stories_table.php); a logged-in user's
// only privilege beyond Guest is now the Grimoire.
return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering routes.');
    }

    $app->group('', function ($group) use ($container): void {
        $group->get('/grimoire', function (Request $request, Response $response) use ($container): Response {
            /** @var GrimoireRepository $grimoire */
            $grimoire = $container->get(GrimoireRepository::class);
            $entries = $grimoire->listForUser((int) SessionAuth::id());

            return Twig::fromRequest($request)->render($response, 'grimoire/index.twig', [
                'entries' => $entries,
            ]);
        });

        $group->post(
            '/grimoire/{recipeId}',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var GrimoireRepository $grimoire */
                $grimoire = $container->get(GrimoireRepository::class);
                $grimoire->add((int) SessionAuth::id(), (int) $args['recipeId']);

                Flash::add('success', 'Added to your Grimoire.');

                return $response->withHeader('Location', '/grimoire')->withStatus(302);
            },
        );

        $group->post(
            '/grimoire/{recipeId}/remove',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var GrimoireRepository $grimoire */
                $grimoire = $container->get(GrimoireRepository::class);
                $grimoire->remove((int) SessionAuth::id(), (int) $args['recipeId']);

                Flash::add('info', 'Removed from your Grimoire.');

                return $response->withHeader('Location', '/grimoire')->withStatus(302);
            },
        );
    })->add(new RequireRoleMiddleware(Roles::USER));
};
