<?php

declare(strict_types=1);

use App\Account\PasswordChangeValidator;
use App\Auth\Roles;
use App\Auth\SessionAuth;
use App\Http\Flash;
use App\Http\Middleware\RequireRoleMiddleware;
use App\Repository\GrimoireRepository;
use App\Repository\PersonalRecipeRepository;
use App\Repository\UserRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

/**
 * Where a bookmark add/remove sends you back to. Defaults to /grimoire
 * (the Grimoire page's own remove buttons don't send a return_to at all),
 * but the recipe detail page's bookmark button does, so toggling it there
 * doesn't yank the reader away to their Grimoire mid-recipe.
 *
 * Only ever a same-site path, never a full URL a POST body could set to
 * anywhere it likes -- must start with exactly one leading "/" (rules out
 * both a bare "no leading slash" value and a protocol-relative "//evil.com"
 * one, which browsers treat as scheme-relative to *any* host) and must not
 * contain "://" (rules out an absolute URL with a scheme). Anything that
 * doesn't pass falls back to /grimoire rather than being trusted.
 *
 * @param array<string, mixed> $data
 */
function grimoireReturnTo(array $data): string
{
    $returnTo = (string) ($data['return_to'] ?? '');

    $isSameSitePath = str_starts_with($returnTo, '/')
        && !str_starts_with($returnTo, '//')
        && !str_contains($returnTo, '://');

    return $isSameSitePath ? $returnTo : '/grimoire';
}

// Logged-in-user routes: the Grimoire. Requires Roles::USER -- see
// spec.md -- Roles & Permissions. Two features live here now: the original
// bookmark list (grimoire_entries -- "recipes I want to try") and a user's
// own private recipes (personal_recipes -- see
// db/migrations/20260824090000_create_personal_recipes_table.php), added
// once she asked for the Grimoire to also hold recipes she's typed in
// herself, private to her. They're unrelated data (one references the
// curated `recipes` table, the other is fully standalone), but both are
// "things in my Grimoire" from a user's point of view, so they share this
// route file and the one /grimoire page.
return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering routes.');
    }

    $app->group('', function ($group) use ($container): void {
        // --- Account: the one editable setting is marketing-email consent
        //     (see db/migrations/20260831140000_add_marketing_opt_in_to_users.php).
        $group->get('/account', function (Request $request, Response $response) use ($container): Response {
            /** @var UserRepository $users */
            $users = $container->get(UserRepository::class);

            return Twig::fromRequest($request)->render($response, 'account/index.twig', [
                'account' => $users->findById((int) SessionAuth::id()),
            ]);
        });

        $group->post('/account', function (Request $request, Response $response) use ($container): Response {
            /** @var array<string, mixed> $data */
            $data = (array) $request->getParsedBody();

            /** @var UserRepository $users */
            $users = $container->get(UserRepository::class);
            $users->setMarketingOptIn((int) SessionAuth::id(), isset($data['marketing_opt_in']));

            Flash::add('success', 'Your preferences have been saved.');

            return $response->withHeader('Location', '/account')->withStatus(302);
        });

        // Self-service password change. Acts purely on the current session's
        // user (SessionAuth::id()) -- there is no admin/impersonation special
        // case here on purpose: whoever the session is currently
        // authenticated as is whose password this changes. Errors flash and
        // redirect back to /account (same style as POST /account above)
        // rather than the 422 re-render pattern, to keep this one-page
        // account area's two forms independent.
        $group->post('/account/password', function (Request $request, Response $response) use ($container): Response {
            /** @var array<string, mixed> $data */
            $data = (array) $request->getParsedBody();

            /** @var UserRepository $users */
            $users = $container->get(UserRepository::class);
            $user = $users->findById((int) SessionAuth::id());

            if ($user === null) {
                Flash::add('error', 'Your current password is incorrect.');

                return $response->withHeader('Location', '/account')->withStatus(302);
            }

            $newPassword = (string) ($data['new_password'] ?? '');

            $error = PasswordChangeValidator::firstError(
                $users->verifyPassword($user, (string) ($data['current_password'] ?? '')),
                $newPassword,
                (string) ($data['new_password_confirm'] ?? ''),
            );

            if ($error !== null) {
                Flash::add('error', $error);

                return $response->withHeader('Location', '/account')->withStatus(302);
            }

            $users->updatePassword((int) SessionAuth::id(), $newPassword);

            Flash::add('success', 'Your password has been changed.');

            return $response->withHeader('Location', '/account')->withStatus(302);
        });

        $group->get('/grimoire', function (Request $request, Response $response) use ($container): Response {
            /** @var GrimoireRepository $grimoire */
            $grimoire = $container->get(GrimoireRepository::class);
            /** @var PersonalRecipeRepository $personalRecipes */
            $personalRecipes = $container->get(PersonalRecipeRepository::class);
            $userId = (int) SessionAuth::id();

            return Twig::fromRequest($request)->render($response, 'grimoire/index.twig', [
                'entries' => $grimoire->listForUser($userId),
                'personalRecipes' => $personalRecipes->listForUser($userId),
            ]);
        });

        $group->post(
            '/grimoire/{recipeId}',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var GrimoireRepository $grimoire */
                $grimoire = $container->get(GrimoireRepository::class);
                $grimoire->add((int) SessionAuth::id(), (int) $args['recipeId']);

                Flash::add('success', 'Added to your Grimoire.');

                /** @var array<string, mixed> $data */
                $data = (array) $request->getParsedBody();

                return $response->withHeader('Location', grimoireReturnTo($data))->withStatus(302);
            },
        );

        $group->post(
            '/grimoire/{recipeId}/remove',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var GrimoireRepository $grimoire */
                $grimoire = $container->get(GrimoireRepository::class);
                $grimoire->remove((int) SessionAuth::id(), (int) $args['recipeId']);

                Flash::add('info', 'Removed from your Grimoire.');

                /** @var array<string, mixed> $data */
                $data = (array) $request->getParsedBody();

                return $response->withHeader('Location', grimoireReturnTo($data))->withStatus(302);
            },
        );

        // --- Personal recipes (private, own-only) --------------------------
        //
        // /grimoire/recipes/... is namespaced apart from /grimoire/{recipeId}
        // above (the bookmark routes), but every POST target here is the
        // same URL as the GET form that renders it (.../new, .../{id}/edit)
        // rather than this codebase's usual GET .../edit + POST .../{id}
        // split (see admin.php's recipe edit routes) -- POST /grimoire/recipes
        // alone would be indistinguishable, at the router level, from
        // POST /grimoire/{recipeId} (the bookmark-add route) with
        // recipeId="recipes": FastRoute refuses to compile two routes that
        // ambiguous for the same HTTP method, so the extra static segment
        // is required here, not just stylistic.

        $group->get('/grimoire/recipes/new', function (Request $request, Response $response): Response {
            return Twig::fromRequest($request)->render($response, 'grimoire/personal_recipe_form.twig', [
                'personalRecipe' => null,
            ]);
        });

        $group->post(
            '/grimoire/recipes/new',
            function (Request $request, Response $response) use ($container): Response {
                /** @var array<string, string> $data */
                $data = (array) $request->getParsedBody();
                $title = trim($data['title'] ?? '');
                $ingredients = (string) ($data['ingredients'] ?? '');
                $instructions = (string) ($data['instructions'] ?? '');

                if ($title === '' || mb_strlen($title) > 255) {
                    Flash::add('error', 'Title must be 1-255 characters.');

                    return Twig::fromRequest($request)->render($response->withStatus(422), 'grimoire/personal_recipe_form.twig', [
                        'personalRecipe' => null,
                        'old' => ['title' => $title, 'ingredients' => $ingredients, 'instructions' => $instructions],
                    ]);
                }

                /** @var PersonalRecipeRepository $personalRecipes */
                $personalRecipes = $container->get(PersonalRecipeRepository::class);
                $id = $personalRecipes->create((int) SessionAuth::id(), $title, $ingredients, $instructions);

                Flash::add('success', 'Added to your Grimoire.');

                return $response->withHeader('Location', '/grimoire/recipes/' . $id)->withStatus(302);
            },
        );

        // The plain view -- clicking a recipe from /grimoire lands here, not
        // straight on the edit form (see personal_recipe_detail.twig).
        // Registered after /grimoire/recipes/new above so FastRoute sees
        // the static "new" segment before this {id} pattern at the same
        // position -- reversing that order makes it refuse to compile the
        // routes at all ("shadowed" route), same reasoning as the comment
        // on the POST routes below.
        $group->get(
            '/grimoire/recipes/{id}',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var PersonalRecipeRepository $personalRecipes */
                $personalRecipes = $container->get(PersonalRecipeRepository::class);
                $personalRecipe = $personalRecipes->findByIdForUser((int) $args['id'], (int) SessionAuth::id());

                if ($personalRecipe === null) {
                    return Twig::fromRequest($request)->render($response->withStatus(404), 'not_found.twig');
                }

                return Twig::fromRequest($request)->render($response, 'grimoire/personal_recipe_detail.twig', [
                    'personalRecipe' => $personalRecipe,
                ]);
            },
        );

        $group->get(
            '/grimoire/recipes/{id}/edit',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var PersonalRecipeRepository $personalRecipes */
                $personalRecipes = $container->get(PersonalRecipeRepository::class);
                $personalRecipe = $personalRecipes->findByIdForUser((int) $args['id'], (int) SessionAuth::id());

                if ($personalRecipe === null) {
                    return Twig::fromRequest($request)->render($response->withStatus(404), 'not_found.twig');
                }

                return Twig::fromRequest($request)->render($response, 'grimoire/personal_recipe_form.twig', [
                    'personalRecipe' => $personalRecipe,
                ]);
            },
        );

        $group->post(
            '/grimoire/recipes/{id}/edit',
            function (Request $request, Response $response, array $args) use ($container): Response {
                $id = (int) $args['id'];
                $userId = (int) SessionAuth::id();

                /** @var PersonalRecipeRepository $personalRecipes */
                $personalRecipes = $container->get(PersonalRecipeRepository::class);
                $personalRecipe = $personalRecipes->findByIdForUser($id, $userId);

                if ($personalRecipe === null) {
                    return Twig::fromRequest($request)->render($response->withStatus(404), 'not_found.twig');
                }

                /** @var array<string, string> $data */
                $data = (array) $request->getParsedBody();
                $title = trim($data['title'] ?? '');
                $ingredients = (string) ($data['ingredients'] ?? '');
                $instructions = (string) ($data['instructions'] ?? '');

                if ($title === '' || mb_strlen($title) > 255) {
                    Flash::add('error', 'Title must be 1-255 characters.');

                    return Twig::fromRequest($request)->render($response->withStatus(422), 'grimoire/personal_recipe_form.twig', [
                        'personalRecipe' => $personalRecipe,
                        'old' => ['title' => $title, 'ingredients' => $ingredients, 'instructions' => $instructions],
                    ]);
                }

                $personalRecipes->update($id, $userId, $title, $ingredients, $instructions);

                Flash::add('success', 'Recipe saved.');

                return $response->withHeader('Location', '/grimoire/recipes/' . $id)->withStatus(302);
            },
        );

        $group->post(
            '/grimoire/recipes/{id}/delete',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var PersonalRecipeRepository $personalRecipes */
                $personalRecipes = $container->get(PersonalRecipeRepository::class);
                $personalRecipes->delete((int) $args['id'], (int) SessionAuth::id());

                Flash::add('info', 'Removed from your Grimoire.');

                return $response->withHeader('Location', '/grimoire')->withStatus(302);
            },
        );
    })->add(new RequireRoleMiddleware(Roles::USER, $container->get(UserRepository::class)));
};
