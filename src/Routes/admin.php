<?php

declare(strict_types=1);

use App\Auth\Roles;
use App\Auth\SessionAuth;
use App\Http\Flash;
use App\Http\Middleware\RequireRoleMiddleware;
use App\Mail\RecipeNotifications;
use App\Recipe\Narrators;
use App\Repository\RecipeEmailQueueRepository;
use App\Repository\RecipeRepository;
use App\Repository\StoryRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Scraping\RecipeImporter;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

// Admin routes: recipe CRUD/import review, tags. Entirely gated behind
// Roles::ADMIN -- see spec.md -- Roles & Permissions ("flat, equal power
// across all admins"), so there's no per-route sub-permission checking
// beyond the group middleware.
//
// There is currently NO way to create the first admin account except direct
// DB access (`UPDATE users SET role = 'admin' WHERE id = ...`) -- no
// admin-creation UI/seeder exists. Fine for a personal project's initial
// launch, but worth knowing before deploying somewhere that needs one.

/**
 * Where the publish/unpublish toggle sends you back to. Used from two
 * places with different expectations: recipe_edit.twig wants to stay on
 * the edit page (the original/default behaviour), while the one-click
 * status light on recipes_index.twig wants to stay on the list instead of
 * bouncing over to edit on every click -- distinguished by a hidden
 * `return_to=list` field the list's form sets and the edit page's forms
 * don't. When it is the list, recipesListReturnTo() below preserves
 * whatever page/search/tag/status the list form was showing, so toggling a
 * recipe's status from page 3 of a filtered view doesn't bounce back to
 * page 1 unfiltered.
 */
function publishToggleReturnTo(Request $request, int $recipeId): string
{
    /** @var array<string, mixed> $data */
    $data = (array) $request->getParsedBody();

    return ($data['return_to'] ?? '') === 'list'
        ? recipesListReturnTo($data)
        : '/admin/recipes/' . $recipeId . '/edit';
}

/**
 * Builds the /admin/recipes?... redirect target from a row-level action
 * form's hidden page/q/tag/status fields.
 *
 * @param array<string, mixed> $data
 */
function recipesListReturnTo(array $data): string
{
    $params = ['page' => (int) ($data['page'] ?? 1)];

    $q = (string) ($data['q'] ?? '');
    if ($q !== '') {
        $params['q'] = $q;
    }

    $tag = (string) ($data['tag'] ?? '');
    if ($tag !== '') {
        $params['tag'] = $tag;
    }

    $status = (string) ($data['status'] ?? '');
    if ($status !== '') {
        $params['status'] = $status;
    }

    return '/admin/recipes?' . http_build_query($params);
}

return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering routes.');
    }

    $app->group('/admin', function ($group) use ($container): void {
        $group->get('', function (Request $request, Response $response) use ($container): Response {
            /** @var RecipeRepository $recipes */
            $recipes = $container->get(RecipeRepository::class);

            return Twig::fromRequest($request)->render($response, 'admin/dashboard.twig', [
                'recipeCount' => $recipes->countAllFiltered(),
                'draftCount' => $recipes->countDrafts(),
            ]);
        });

        // --- Recipes ------------------------------------------------------

        // Search + tag filter + status filter + pagination (200/page).
        $group->get('/recipes', function (Request $request, Response $response) use ($container): Response {
            /** @var RecipeRepository $recipes */
            $recipes = $container->get(RecipeRepository::class);
            /** @var TagRepository $tags */
            $tags = $container->get(TagRepository::class);

            $query = $request->getQueryParams();
            $page = max(1, (int) ($query['page'] ?? 1));
            $limit = 200;
            $search = trim((string) ($query['q'] ?? ''));
            $tagId = isset($query['tag']) && ctype_digit((string) $query['tag']) ? (int) $query['tag'] : null;
            $titleSearch = $search !== '' ? $search : null;
            $status = in_array($query['status'] ?? null, ['draft', 'published'], true) ? $query['status'] : null;

            $rows = $recipes->listAllFiltered($limit, ($page - 1) * $limit, $titleSearch, $tagId, $status);
            $total = $recipes->countAllFiltered($titleSearch, $tagId, $status);

            $recipeIds = array_column($rows, 'id');

            return Twig::fromRequest($request)->render($response, 'admin/recipes_index.twig', [
                'recipes' => $rows,
                'allTags' => $tags->all(),
                'tagIdsByRow' => $tags->tagIdsByRecipeIds($recipeIds),
                'search' => $search,
                'tagId' => $tagId,
                'status' => $status,
                'page' => $page,
                'total' => $total,
                'hasNextPage' => ($page - 1) * $limit + count($rows) < $total,
            ]);
        });

        // Web-UI equivalent of `php bin/console recipe:import <url>` -- both
        // share the fetch/extract/validate pipeline in RecipeImporter, so
        // there's exactly one place that logic lives. Confirmed working
        // against BBC Good Food, HelloFresh (UK), and Riverford (see
        // RecipeImporter's docblock), though it isn't restricted to just
        // those three.
        $group->get('/recipes/import', function (Request $request, Response $response): Response {
            return Twig::fromRequest($request)->render($response, 'admin/recipe_import.twig');
        });

        $group->post('/recipes/import', function (Request $request, Response $response) use ($container): Response {
            /** @var array<string, string> $data */
            $data = (array) $request->getParsedBody();
            $url = trim($data['url'] ?? '');
            $force = isset($data['force']) && $data['force'] === '1';

            if ($url === '') {
                return Twig::fromRequest($request)->render($response->withStatus(422), 'admin/recipe_import.twig', [
                    'error' => 'Enter a recipe URL to import.',
                    'old' => ['url' => $url, 'force' => $force],
                ]);
            }

            /** @var RecipeImporter $importer */
            $importer = $container->get(RecipeImporter::class);
            $result = $importer->import($url, $force);

            if ($result->extractionFailed) {
                // robots.txt disallowed it, a fetch/HTTP error, or no usable
                // structured recipe data -- automation is a dead end for
                // this URL, so hand off to manual entry with the source
                // page open alongside instead of just showing an error and
                // stopping (see RecipeImportResult's docblock).
                Flash::add('error', (string) $result->error);

                return $response
                    ->withHeader('Location', '/admin/recipes/import/manual?' . http_build_query(['url' => $url]))
                    ->withStatus(302);
            }

            if (!$result->success) {
                // Only "already imported" reaches here -- automation itself
                // still works, so stay on this form where ticking "force"
                // retries it, rather than bouncing to manual entry.
                return Twig::fromRequest($request)->render($response->withStatus(422), 'admin/recipe_import.twig', [
                    'error' => $result->error,
                    'old' => ['url' => $url, 'force' => $force],
                ]);
            }

            Flash::add('success', sprintf('Imported "%s" as a draft.', $result->title));

            return $response->withHeader('Location', '/admin/recipes/' . $result->recipeId . '/edit')->withStatus(302);
        });

        // Manual-entry fallback for a URL automated import couldn't handle
        // -- same fields as /recipes/new (and posts to the same /recipes
        // route below), but with the source page open in an iframe
        // alongside them so the admin can copy the recipe across by hand
        // while looking at it. Also reachable directly, not just via the
        // redirect above, for a URL an admin already knows won't
        // auto-import.
        //
        // Not every site allows being framed this way -- BBC Good Food and
        // Riverford both send X-Frame-Options: SAMEORIGIN, so their iframe
        // renders blank -- hence recipe_import_manual.twig's "open in a new
        // tab" link, which works regardless.
        $group->get('/recipes/import/manual', function (Request $request, Response $response): Response {
            $url = trim((string) ($request->getQueryParams()['url'] ?? ''));

            return Twig::fromRequest($request)->render($response, 'admin/recipe_import_manual.twig', [
                'sourceUrl' => $url,
                'old' => ['origin' => $url],
            ]);
        });

        $group->get('/recipes/new', function (Request $request, Response $response): Response {
            return Twig::fromRequest($request)->render($response, 'admin/recipe_new.twig');
        });

        // Everything the recipes table can hold at creation time is
        // captured on this single form rather than split across a
        // create-then-edit flow -- narrator/narrator_recipe/story/tags can
        // still be filled in afterward on the edit page this redirects to;
        // only slug/title are required, matching what the DB itself
        // requires to create the row at all (original_ingredients/
        // original_instructions are NOT NULL but may be empty strings).
        //
        // Shared by both /recipes/new (plain manual entry) and
        // /recipes/import/manual (manual entry with the source open in an
        // iframe, for a URL automated import couldn't handle) -- they're
        // the same fields posting to the same route, told apart only by a
        // hidden `form` field so a validation failure re-renders whichever
        // page the admin was actually on instead of always the plain one.
        $group->post('/recipes', function (Request $request, Response $response) use ($container): Response {
            /** @var array<string, string> $data */
            $data = (array) $request->getParsedBody();
            $slug = trim($data['slug'] ?? '');
            $title = trim($data['title'] ?? '');
            $origin = trim((string) ($data['origin'] ?? ''));
            $originalIngredients = (string) ($data['original_ingredients'] ?? '');
            $originalInstructions = (string) ($data['original_instructions'] ?? '');
            $published = isset($data['published']) && $data['published'] === '1';
            $errorTemplate = ($data['form'] ?? '') === 'manual_import'
                ? 'admin/recipe_import_manual.twig'
                : 'admin/recipe_new.twig';

            $errors = [];

            if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
                $errors[] = 'Slug must be lowercase letters, numbers, and hyphens only (e.g. "dragons-breath-stew").';
            }

            if ($title === '' || mb_strlen($title) > 255) {
                $errors[] = 'Title must be 1-255 characters.';
            }

            /** @var RecipeRepository $recipes */
            $recipes = $container->get(RecipeRepository::class);

            if ($errors === [] && $recipes->findBySlug($slug) !== null) {
                $errors[] = 'That slug is already in use.';
            }

            if ($errors !== []) {
                return Twig::fromRequest($request)->render($response->withStatus(422), $errorTemplate, [
                    'errors' => $errors,
                    'sourceUrl' => $origin,
                    'old' => [
                        'slug' => $slug,
                        'title' => $title,
                        'origin' => $origin,
                        'original_ingredients' => $originalIngredients,
                        'original_instructions' => $originalInstructions,
                        'published' => $published,
                    ],
                ]);
            }

            $recipeId = $recipes->create($slug, $title, $origin !== '' ? $origin : null, $originalIngredients, $originalInstructions);

            if ($published) {
                $recipes->setPublished($recipeId, true);
            }

            Flash::add('success', sprintf('Created "%s".', $title));

            return $response->withHeader('Location', '/admin/recipes/' . $recipeId . '/edit')->withStatus(302);
        });

        $group->get(
            '/recipes/{id}/edit',
            function (Request $request, Response $response, array $args) use ($container): Response {
                $recipeId = (int) $args['id'];

                /** @var RecipeRepository $recipes */
                $recipes = $container->get(RecipeRepository::class);
                $recipe = $recipes->findById($recipeId);

                if ($recipe === null) {
                    return Twig::fromRequest($request)->render($response->withStatus(404), 'not_found.twig');
                }

                /** @var TagRepository $tags */
                $tags = $container->get(TagRepository::class);

                /** @var StoryRepository $stories */
                $stories = $container->get(StoryRepository::class);
                $story = $recipe['story_id'] !== null ? $stories->findById((int) $recipe['story_id']) : null;

                return Twig::fromRequest($request)->render($response, 'admin/recipe_edit.twig', [
                    'recipe' => $recipe,
                    'story' => $story,
                    'archivedStories' => $stories->listArchived($recipeId),
                    'allTags' => $tags->all(),
                    'recipeTags' => $tags->tagsForRecipe($recipeId),
                    'narrators' => Narrators::NAMES,
                    // recipesListReturnTo() reads the same page/q/tag/status
                    // keys whether they come from a POST body (the publish/
                    // unpublish/delete redirects) or, here, the query
                    // string -- recipes_index.twig's Edit link carries its
                    // current page/q/tag/status forward so Back lands on the
                    // same filtered/paginated view the admin actually came
                    // from, not an unfiltered page 1.
                    'backUrl' => recipesListReturnTo($request->getQueryParams()),
                ]);
            },
        );

        // The recipe edit page's single Save button -- everything that's
        // "draft content an admin fills in over time" (details, the
        // mundane truth, the narrator + ritual recipe, and the Story) is
        // one form now, saved together by this one handler, rather than
        // four separate forms each with their own Save button and their
        // own silently-lost-if-you-forget-to-click-it section. Tags
        // (auto-saves per pill via its own widget -- see site.js),
        // Publish/Unpublish (an immediate status action), and Delete
        // (destructive, wants its own confirm) are deliberately NOT part
        // of this -- see recipe_edit.twig's comment on the merged form.
        //
        // All-or-nothing: any validation failure (slug, title, narrator)
        // saves nothing at all, rather than partially applying whichever
        // fields happened to validate -- an admin fixing a typo'd slug
        // shouldn't have to wonder whether their ingredients edit further
        // down the same page silently didn't take.
        $group->post(
            '/recipes/{id}',
            function (Request $request, Response $response, array $args) use ($container): Response {
                $recipeId = (int) $args['id'];

                /** @var RecipeRepository $recipes */
                $recipes = $container->get(RecipeRepository::class);
                $recipe = $recipes->findById($recipeId);

                if ($recipe === null) {
                    return Twig::fromRequest($request)->render($response->withStatus(404), 'not_found.twig');
                }

                /** @var array<string, string> $data */
                $data = (array) $request->getParsedBody();
                $slug = trim($data['slug'] ?? '');
                $title = trim($data['title'] ?? '');
                $originalIngredients = (string) ($data['original_ingredients'] ?? '');
                $originalInstructions = (string) ($data['original_instructions'] ?? '');
                $narrator = trim($data['narrator'] ?? '');
                $narratorRecipe = (string) ($data['narrator_recipe'] ?? '');
                $storyBody = trim($data['story_body'] ?? '');

                $errors = [];

                if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
                    $errors[] = 'Slug must be lowercase letters, numbers, and hyphens only.';
                }

                if ($title === '' || mb_strlen($title) > 255) {
                    $errors[] = 'Title must be 1-255 characters.';
                }

                $existing = $errors === [] ? $recipes->findBySlug($slug) : null;
                if ($existing !== null && (int) $existing['id'] !== $recipeId) {
                    $errors[] = 'That slug is already in use by another recipe.';
                }

                if (!Narrators::isValid($narrator)) {
                    $errors[] = 'Choose a narrator from the list.';
                }

                if ($errors !== []) {
                    Flash::add('error', implode(' ', $errors));

                    return $response->withHeader('Location', '/admin/recipes/' . $recipeId . '/edit')->withStatus(302);
                }

                $recipes->update($recipeId, $slug, $title);
                $recipes->updateOriginalIngredients($recipeId, $originalIngredients);
                $recipes->updateOriginalInstructions($recipeId, $originalInstructions);
                $recipes->updateNarratorRecipe($recipeId, $narrator, $narratorRecipe);

                // A blank Story textarea leaves whatever Story already
                // exists untouched rather than blocking the whole save or
                // deleting it -- the Story is optional here the same way
                // it always has been (a recipe with "none yet" is a normal
                // state), so an admin editing everything else on this page
                // shouldn't be forced to also have Story text ready.
                if ($storyBody !== '') {
                    /** @var StoryRepository $stories */
                    $stories = $container->get(StoryRepository::class);

                    if ($recipe['story_id'] !== null) {
                        $stories->update((int) $recipe['story_id'], $storyBody);
                    } else {
                        $stories->create($recipeId, $storyBody, SessionAuth::id());
                    }
                }

                Flash::add('success', 'Recipe saved.');

                return $response->withHeader('Location', '/admin/recipes/' . $recipeId . '/edit')->withStatus(302);
            },
        );

        $group->post(
            '/recipes/{id}/publish',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var RecipeRepository $recipes */
                $recipes = $container->get(RecipeRepository::class);
                $recipes->setPublished((int) $args['id'], true);
                Flash::add('success', 'Recipe published.');

                return $response->withHeader('Location', publishToggleReturnTo($request, (int) $args['id']))
                    ->withStatus(302);
            },
        );

        $group->post(
            '/recipes/{id}/unpublish',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var RecipeRepository $recipes */
                $recipes = $container->get(RecipeRepository::class);
                $recipes->setPublished((int) $args['id'], false);
                Flash::add('info', 'Recipe unpublished.');

                return $response->withHeader('Location', publishToggleReturnTo($request, (int) $args['id']))
                    ->withStatus(302);
            },
        );

        // A real delete (RecipeRepository::delete() -- recipe_tags, stories,
        // and grimoire_entries all cascade via FK, see the migrations), not
        // a soft one. Reachable from both recipes_index.twig (the list,
        // where hidden page/q/tag/status fields round-trip back to the same
        // filtered view via recipesListReturnTo()) and recipe_edit.twig
        // (where there's nothing to round-trip to -- the recipe is gone --
        // so that form just omits them and recipesListReturnTo() falls back
        // to an unfiltered page 1).
        //
        // The list page's delete button is AJAX (see the
        // .recipe-delete-form handler in public/js/site.js) -- it removes
        // the row client-side instead of reloading/re-paginating the whole
        // list. Detected via the conventional X-Requested-With header
        // (fetch, unlike a plain form post, has to set it explicitly -- see
        // site.js) rather than Accept, so the plain-form fallback (JS
        // disabled, or the edit page's delete button, which intentionally
        // is NOT AJAX -- there's no "row" to remove, the whole page needs
        // to navigate away) keeps working unchanged.
        $group->post(
            '/recipes/{id}/delete',
            function (Request $request, Response $response, array $args) use ($container): Response {
                $recipeId = (int) $args['id'];

                /** @var RecipeRepository $recipes */
                $recipes = $container->get(RecipeRepository::class);
                $recipes->delete($recipeId);

                if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                    // slim/csrf tokens are single-use by default (Guard
                    // deletes a token from its pool the moment it validates
                    // successfully -- see Guard::process(), and this app
                    // never enables persistentTokenMode). Every row on this
                    // page was rendered with the SAME token pair, so
                    // consuming it here would silently break every other
                    // row's still-stale delete/publish form. Guard has
                    // already minted a fresh pair onto this request's
                    // attributes by the time control reaches here; hand it
                    // back so the client can patch every csrf_name/
                    // csrf_value input on the page before its next action.
                    $payload = json_encode([
                        'success' => true,
                        'csrf_name' => $request->getAttribute('csrf_name'),
                        'csrf_value' => $request->getAttribute('csrf_value'),
                    ], JSON_THROW_ON_ERROR);

                    $response->getBody()->write($payload);

                    return $response->withHeader('Content-Type', 'application/json');
                }

                Flash::add('info', 'Recipe deleted.');

                /** @var array<string, mixed> $data */
                $data = (array) $request->getParsedBody();

                return $response->withHeader('Location', recipesListReturnTo($data))->withStatus(302);
            },
        );

        $group->post(
            '/recipes/{id}/tags',
            function (Request $request, Response $response, array $args) use ($container): Response {
                $recipeId = (int) $args['id'];

                /** @var array<string, mixed> $data */
                $data = (array) $request->getParsedBody();
                /** @var list<string> $rawIds */
                $rawIds = is_array($data['tag_ids'] ?? null) ? $data['tag_ids'] : [];
                $tagIds = array_map('intval', $rawIds);

                /** @var TagRepository $tags */
                $tags = $container->get(TagRepository::class);
                $tags->setTagsForRecipe($recipeId, $tagIds);

                // The pill picker (site.js -- "Admin: recipe tags pill
                // picker") saves on every add/remove instead of waiting for
                // a submit click, posting here the same way the row delete
                // and inline tag-create do -- see those handlers' comments
                // for why the fresh CSRF pair has to come back too.
                if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                    $payload = json_encode([
                        'success' => true,
                        'csrf_name' => $request->getAttribute('csrf_name'),
                        'csrf_value' => $request->getAttribute('csrf_value'),
                    ], JSON_THROW_ON_ERROR);

                    $response->getBody()->write($payload);

                    return $response->withHeader('Content-Type', 'application/json');
                }

                Flash::add('success', 'Tags updated.');

                return $response->withHeader('Location', '/admin/recipes/' . $recipeId . '/edit')->withStatus(302);
            },
        );

        // --- Tags -------------------------------------------------------------
        //
        // Merge/dedupe tooling is explicitly out of scope (spec.md -- Domain
        // Model: Tag) -- create/rename/delete only.

        $group->get('/tags', function (Request $request, Response $response) use ($container): Response {
            /** @var TagRepository $tags */
            $tags = $container->get(TagRepository::class);

            return Twig::fromRequest($request)->render($response, 'admin/tags.twig', [
                'tags' => $tags->all(),
            ]);
        });

        $group->post('/tags', function (Request $request, Response $response) use ($container): Response {
            /** @var array<string, string> $data */
            $data = (array) $request->getParsedBody();
            $name = trim($data['name'] ?? '');

            /** @var TagRepository $tags */
            $tags = $container->get(TagRepository::class);

            $error = null;
            $created = null;

            if ($name === '' || mb_strlen($name) > 64) {
                $error = 'Tag name must be 1-64 characters.';
            } elseif ($tags->findByName($name) !== null) {
                $error = 'That tag already exists.';
            } else {
                $created = ['id' => $tags->create($name), 'name' => $name];
            }

            // The "create tag" affordance in the pill picker on
            // recipe_edit.twig (site.js -- "Admin: recipe tags pill
            // picker") posts here via fetch so it can drop the new tag
            // straight into the picker instead of round-tripping the whole
            // edit page. Same X-Requested-With convention, and same
            // CSRF-rotation hand-back, as the recipe row delete above --
            // see that handler's comment for why the fresh pair is needed.
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $payload = json_encode([
                    'success' => $error === null,
                    'error' => $error,
                    'tag' => $created,
                    'csrf_name' => $request->getAttribute('csrf_name'),
                    'csrf_value' => $request->getAttribute('csrf_value'),
                ], JSON_THROW_ON_ERROR);

                $response->getBody()->write($payload);

                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus($error === null ? 200 : 422);
            }

            if ($error !== null) {
                Flash::add('error', $error);
            } else {
                Flash::add('success', sprintf('Tag "%s" created.', $name));
            }

            return $response->withHeader('Location', '/admin/tags')->withStatus(302);
        });

        $group->post(
            '/tags/{id}',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var array<string, string> $data */
                $data = (array) $request->getParsedBody();
                $name = trim($data['name'] ?? '');

                if ($name === '' || mb_strlen($name) > 64) {
                    Flash::add('error', 'Tag name must be 1-64 characters.');

                    return $response->withHeader('Location', '/admin/tags')->withStatus(302);
                }

                /** @var TagRepository $tags */
                $tags = $container->get(TagRepository::class);
                $tags->rename((int) $args['id'], $name);

                Flash::add('success', 'Tag renamed.');

                return $response->withHeader('Location', '/admin/tags')->withStatus(302);
            },
        );

        $group->post(
            '/tags/{id}/delete',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var TagRepository $tags */
                $tags = $container->get(TagRepository::class);
                $tags->delete((int) $args['id']);

                Flash::add('info', 'Tag deleted.');

                return $response->withHeader('Location', '/admin/tags')->withStatus(302);
            },
        );

        // --- Recipe-email queue -----------------------------------------
        //
        // Visibility of, and control over, the "new recipe(s)" marketing
        // emails (see App\Mail\RecipeNotifications and
        // db/migrations/20260831150000_create_recipe_email_queue.php).

        $group->get('/mail-queue', function (Request $request, Response $response) use ($container): Response {
            /** @var RecipeEmailQueueRepository $queue */
            $queue = $container->get(RecipeEmailQueueRepository::class);
            $campaigns = $queue->listCampaigns();

            $ids = array_map(static fn (array $c): int => (int) $c['id'], $campaigns);
            $titles = $queue->recipeTitlesFor($ids);

            $rows = [];
            foreach ($campaigns as $campaign) {
                $rows[] = $campaign + [
                    'recipe_titles' => $titles[(int) $campaign['id']] ?? [],
                    'delivery_counts' => $queue->deliveryStatusCounts((int) $campaign['id']),
                ];
            }

            return Twig::fromRequest($request)->render($response, 'admin/mail_queue.twig', [
                'campaigns' => $rows,
                'awaiting' => count($container->get(RecipeRepository::class)->listPublishedAwaitingAnnouncement()),
            ]);
        });

        $group->post('/mail-queue/check', function (Request $request, Response $response) use ($container): Response {
            /** @var RecipeNotifications $notifications */
            $notifications = $container->get(RecipeNotifications::class);
            $id = $notifications->enqueuePending();

            Flash::add($id === null ? 'info' : 'success', $id === null
                ? 'Nothing new to announce.'
                : sprintf('Queued campaign #%d.', $id));

            return $response->withHeader('Location', '/admin/mail-queue')->withStatus(302);
        });

        $group->post(
            '/mail-queue/{id}/send',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var RecipeNotifications $notifications */
                $notifications = $container->get(RecipeNotifications::class);
                $result = $notifications->sendCampaign((int) $args['id']);

                Flash::add($result['stopped'] ? 'error' : 'success', sprintf(
                    '%d sent, %d failed%s.',
                    $result['sent'],
                    $result['failed'],
                    $result['stopped'] ? ' -- campaign paused, retry to resume' : '',
                ));

                return $response->withHeader('Location', '/admin/mail-queue')->withStatus(302);
            },
        );

        $group->post(
            '/mail-queue/{id}/retry',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var RecipeNotifications $notifications */
                $notifications = $container->get(RecipeNotifications::class);
                $result = $notifications->retryCampaign((int) $args['id']);

                Flash::add($result['stopped'] ? 'error' : 'success', sprintf(
                    'Retry: %d sent, %d failed.',
                    $result['sent'],
                    $result['failed'],
                ));

                return $response->withHeader('Location', '/admin/mail-queue')->withStatus(302);
            },
        );

        $group->post(
            '/mail-queue/{id}/cancel',
            function (Request $request, Response $response, array $args) use ($container): Response {
                /** @var RecipeNotifications $notifications */
                $notifications = $container->get(RecipeNotifications::class);
                $cancelled = $notifications->cancelCampaign((int) $args['id']);

                Flash::add($cancelled ? 'info' : 'error', $cancelled
                    ? 'Campaign cancelled. Its recipes will be re-announced on the next check.'
                    : 'Too late -- that campaign has already sent or is sending.');

                return $response->withHeader('Location', '/admin/mail-queue')->withStatus(302);
            },
        );
    })->add(new RequireRoleMiddleware(Roles::ADMIN, $container->get(UserRepository::class)));
};
