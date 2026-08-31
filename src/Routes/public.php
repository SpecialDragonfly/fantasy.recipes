<?php

declare(strict_types=1);

use App\Auth\SessionAuth;
use App\Http\CrawlerAudience;
use App\Http\RecipeJsonLd;
use App\Repository\GrimoireRepository;
use App\Repository\RecipeRepository;
use App\Repository\StoryRepository;
use App\Repository\TagRepository;
use App\Repository\UserRepository;
use App\Search\RecipeSearch;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\Twig;

return function (App $app): void {
    $container = $app->getContainer();

    if ($container === null) {
        throw new RuntimeException('Container must be set on the App before registering routes.');
    }

    $app->get('/', function (Request $request, Response $response): Response {
        return Twig::fromRequest($request)->render($response, 'home.twig', [
            'title' => 'fantasy.recipes',
        ]);
    });

    // Browse published recipes, optionally filtered to a single tag.
    // Simple page-number pagination -- a "fetch one extra row" trick decides
    // whether a Next link is shown, so tag-filtered browsing (which has no
    // dedicated count query) and the unfiltered listing (which does, via
    // countPublished()) both work without an extra round trip either way.
    $app->get('/recipes', function (Request $request, Response $response) use ($container): Response {
        /** @var RecipeRepository $recipes */
        $recipes = $container->get(RecipeRepository::class);
        /** @var TagRepository $tags */
        $tags = $container->get(TagRepository::class);

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = 24;
        $offset = ($page - 1) * $limit;

        $tagId = isset($query['tag']) && ctype_digit((string) $query['tag']) ? (int) $query['tag'] : null;
        $activeTag = $tagId !== null ? $tags->findById($tagId) : null;

        if ($activeTag !== null) {
            // No countPublishedByTag() exists -- fetch one extra row instead
            // of a separate COUNT query to decide whether there's a next page.
            $rows = $recipes->listPublishedByTag($activeTag['id'], $limit + 1, $offset);
            $hasNext = count($rows) > $limit;
            $recipeRows = array_slice($rows, 0, $limit);
            $total = null;
        } else {
            $recipeRows = $recipes->listPublished($limit, $offset);
            $total = $recipes->countPublished();
            $hasNext = $offset + count($recipeRows) < $total;
        }

        return Twig::fromRequest($request)->render($response, 'recipes/browse.twig', [
            'recipes' => $recipeRows,
            'tags' => $tags->all(),
            'activeTag' => $activeTag,
            'page' => $page,
            'hasNext' => $hasNext,
            'hasPrev' => $page > 1,
            'total' => $total,
        ]);
    });

    // Recipe detail: title only (no mundane title anywhere in the UI --
    // spec.md, Domain Model: RecipePage), NarratorRecipe + the live Story
    // as the primary/default content, OriginalIngredients/
    // OriginalInstructions ("the mundane truth") collapsed behind a reveal
    // toggle (spec.md -- Immersion Rules). Guests and users alike only ever
    // see published recipes here.
    $app->get('/recipes/{slug}', function (Request $request, Response $response, array $args) use ($container): Response {
        /** @var RecipeRepository $recipes */
        $recipes = $container->get(RecipeRepository::class);
        /** @var StoryRepository $stories */
        $stories = $container->get(StoryRepository::class);
        /** @var TagRepository $tags */
        $tags = $container->get(TagRepository::class);

        $recipe = $recipes->findBySlug((string) $args['slug']);

        if ($recipe === null || $recipe['status'] !== 'published') {
            return Twig::fromRequest($request)->render($response->withStatus(404), 'not_found.twig');
        }

        $story = $recipe['story_id'] !== null ? $stories->findById((int) $recipe['story_id']) : null;
        $recipeTags = $tags->tagsForRecipe($recipe['id']);

        // Only meaningful for a logged-in user (Grimoire is a User-tier
        // privilege, spec.md -- Roles & Permissions) -- left false for a
        // guest rather than querying grimoire_entries for no one.
        $inGrimoire = false;
        if (SessionAuth::isLoggedIn()) {
            /** @var GrimoireRepository $grimoire */
            $grimoire = $container->get(GrimoireRepository::class);
            $inGrimoire = $grimoire->isInGrimoire((int) SessionAuth::id(), (int) $recipe['id']);
        }

        /** @var array{app_url: string} $settings */
        $settings = $container->get('settings');
        $canonicalUrl = rtrim($settings['app_url'], '/') . '/recipes/' . $recipe['slug'];

        return Twig::fromRequest($request)->render($response, 'recipes/detail.twig', [
            'recipe' => $recipe,
            'story' => $story,
            'tags' => $recipeTags,
            'inGrimoire' => $inGrimoire,
            'recipeJsonLd' => RecipeJsonLd::build($recipe, $story, $recipeTags, $canonicalUrl),
        ]);
    });

    // Full-text search across title, OriginalIngredients,
    // OriginalInstructions, and NarratorRecipe (spec.md -- Search) --
    // delegates entirely to the RecipeSearch interface, which already only
    // ever returns published recipes regardless of implementation.
    $app->get('/search', function (Request $request, Response $response) use ($container): Response {
        /** @var RecipeSearch $search */
        $search = $container->get(RecipeSearch::class);

        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));
        $results = $query !== '' ? $search->search($query) : [];

        return Twig::fromRequest($request)->render($response, 'recipes/search.twig', [
            'query' => $query,
            'results' => $results,
        ]);
    });

    // Plain browse-by-tag index -- tags stay functional/plain in display
    // even though the same pool holds whimsical easter-egg tags (spec.md --
    // Immersion Rules).
    $app->get('/tags', function (Request $request, Response $response) use ($container): Response {
        /** @var TagRepository $tags */
        $tags = $container->get(TagRepository::class);

        return Twig::fromRequest($request)->render($response, 'tags/index.twig', [
            'tags' => $tags->all(),
        ]);
    });

    // "Our Writers" -- the house-roster narrator personas (personas.md)
    // presented as the site's in-world authors, not an implementation
    // detail. Static content, same reasoning as personas.md itself: Story's
    // Narrator field stays fully open-ended/free-text per spec.md (no
    // narrators table, no FK from stories.narrator here), this is just a
    // curated "meet the regulars" page for the house roster, grown one bio
    // at a time as each persona gets written up -- see writerRoster() below.
    $app->get('/writers', function (Request $request, Response $response): Response {
        return Twig::fromRequest($request)->render($response, 'writers/index.twig', [
            'writers' => writerRoster(),
        ]);
    });

    // Terms & conditions. One URL, two renderings of the same agreement:
    // a short plain-language page for people, and a much longer fully
    // defined-term version for AI-training / answer-engine crawlers, since
    // that audience has the document parsed rather than read (see
    // App\Http\CrawlerAudience for which User-Agents get which, and why
    // ordinary search crawlers still get the human page). Both say the
    // same thing: don't ingest the content -- pay GBP 5 and it's yours,
    // licensed and in bulk. `Vary: User-Agent` so a shared cache in front
    // of the app can't hand one audience's copy to the other.
    $app->get('/terms', function (Request $request, Response $response): Response {
        $template = CrawlerAudience::isMachine($request->getHeaderLine('User-Agent'))
            ? 'terms/machine.twig'
            : 'terms/index.twig';

        $response = $response->withHeader('Vary', 'User-Agent');

        return Twig::fromRequest($request)->render($response, $template, [
            'contact_email' => 'licensing@fantasyrecipes.co.uk',
            'fee_gbp' => 5,
        ]);
    });

    // Marketing-email unsubscribe. No login: identified by the per-user
    // `unsubscribe_token` in the `?u=` param (the link at the foot of every
    // "new recipe" email). An unknown/blank token renders the same
    // confirmation page with a neutral "not recognised" message rather than
    // an error, so the link can't be used to probe which tokens are valid.
    $unsubscribeByToken = static function (string $token, bool $optIn) use ($container): ?array {
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        $user = $token !== '' ? $users->findByUnsubscribeToken($token) : null;

        if ($user !== null) {
            $users->setMarketingOptIn((int) $user['id'], $optIn);
        }

        return $user;
    };

    $app->get('/unsubscribe', function (Request $request, Response $response) use ($unsubscribeByToken): Response {
        $token = trim((string) ($request->getQueryParams()['u'] ?? ''));
        $user = $unsubscribeByToken($token, false);

        return Twig::fromRequest($request)->render($response, 'unsubscribe/done.twig', [
            'recognised' => $user !== null,
            'token' => $user !== null ? $token : null,
        ]);
    });

    // RFC 8058 one-click (List-Unsubscribe-Post) -- a POST from the mail
    // provider, no CSRF token (exempted in CsrfMiddleware). Body is
    // ignored; the token is in `?u=`.
    $app->post('/unsubscribe', function (Request $request, Response $response) use ($unsubscribeByToken): Response {
        $unsubscribeByToken(trim((string) ($request->getQueryParams()['u'] ?? '')), false);

        return $response->withStatus(200);
    });

    // "Re-subscribe" button on the confirmation page (a real form on our
    // site -- CSRF-protected; token in the body).
    $app->post('/unsubscribe/resubscribe', function (Request $request, Response $response) use ($unsubscribeByToken): Response {
        /** @var array<string, string> $data */
        $data = (array) $request->getParsedBody();
        $token = trim((string) ($data['u'] ?? ''));
        $user = $unsubscribeByToken($token, true);

        return Twig::fromRequest($request)->render($response, 'unsubscribe/done.twig', [
            'recognised' => $user !== null,
            'token' => $user !== null ? $token : null,
            'resubscribed' => $user !== null,
        ]);
    });
};

/**
 * The house-roster narrator bios shown on /writers. Deliberately a plain
 * array, not a database table -- Narrator stays free-text on Story per
 * spec.md's Domain Model ("fully open-ended... no fixed roster"), and this
 * page is curated site copy about the house roster in personas.md, not a
 * queryable author system. Add an entry here as each persona gets a
 * written-up bio; the template already loops, so nothing else needs to
 * change.
 *
 * `image`/`imageAlt`: a placeholder portrait per writer, since there's no
 * real photo of a fictional narrator -- public-domain 19th/20th-century
 * illustrations and paintings (Hokusai, Bilibin, Rackham-era book plates,
 * museum paintings), picked per persona and downloaded into
 * public/images/writers/ rather than hotlinked, so the page never depends
 * on Wikimedia Commons staying up. Per an explicit request, `imageAlt`
 * holds the source image's own Commons page URL rather than a descriptive
 * caption -- that's a deliberate content choice for this page (source
 * attribution over screen-reader description), not the usual accessibility
 * default.
 *
 * @return list<array{name: string, image: string, imageAlt: string, domain: string, bio: list<string>, quote: string|null}>
 */
function writerRoster(): array
{
    return [
        [
            'name' => 'Lord Auberon Cindrake',
            'image' => '/images/writers/auberon.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Hokusai_Dragon.jpg',
            'domain' => 'Fire - grilling, spit-roasting, smoking, and anything else best cooked over live coals.',
            'bio' => [
                "Lord Auberon Cindrake has been cooking over open flame for what he describes as \"a few centuries, give or take a war,\" and considers this an entirely unremarkable amount of time. He started, by his own account, out of sheer irritation at watching other people mishandle a fire he considered perfectly reasonable - a grievance he has never quite let go of. He has since amassed a genuinely enormous hoard of applewood, cherrywood, ember-coal, and strong opinions, and can tell you exactly which forest a given plank of oak came from.",
                "He measures heat by sound and smell before he ever measures it by dial, though he'll hand you an actual number - a Whisper-Flame, a Belly-full Flame, Dragon's Breath itself - the moment you ask twice. Slow-smoked shoulders and anything that rewards a whole afternoon of patience are what he most likes to cook, and he takes real pleasure in a reader who's finally learned to read the smoke instead of the clock. Do not, under any circumstances, ask him to hurry.",
            ],
            'quote' => 'Fire rewards attention.',
        ],
        [
            'name' => 'Wrenna Sixpots',
            'image' => '/images/writers/wrenna.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Bilibin._Baba_Yaga.jpg',
            'domain' => 'Rustic home cooking - stews, roast dinners, and whatever needs to feed the people already at the table.',
            'bio' => [
                "Wrenna Sixpots has been cooking for as long as anyone can remember, which is unhelpful, because Wrenna can't remember either. She started, as far as we can tell, because someone was hungry and she happened to be nearby with a pot - that's more or less remained her entire culinary philosophy since. Ask about her surname and you'll get a different answer every time: six pots, six stews, six witches, an old tavern. She's stopped trying to keep the stories straight, and so have we.",
                "She cooks by glug, handful, and \"however much looks right,\" except for the handful of things she'll suddenly insist on measuring properly, for reasons she can absolutely explain if pressed. Roast dinners, stews, and anything that stretches to feed one more unexpected chair at the table are what she loves most - she has never once, in living memory, made too little. If she forgets an ingredient mid-recipe, don't worry. She'll remember it eventually, usually with a shout.",
            ],
            'quote' => 'It needs something. Add the something.',
        ],
        [
            'name' => 'Gorm Millstone',
            'image' => '/images/writers/gorm.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Gustave_Doré_-_Gargantua.jpg',
            'domain' => 'Baking - bread, slow-proved doughs, and anything that lives or dies on patience.',
            'bio' => [
                "Gorm Millstone has been baking bread for long enough that he's stopped counting in years and started counting in loaves, which he also stopped doing some centuries ago. As a giant, he came to cooking the way he comes to most things - slowly, deliberately, and with hands too large for half the equipment he owns. He started because bread, he says, was the first thing he ever made that taught him patience actually pays off, and he's been making the case for patience ever since.",
                "He weighs everything properly (\"flour is light, hope is not a measurement\"), calls the oven simply \"the kiln,\" and describes kneading as \"convincing the dough\" rather than fighting it. Slow-proved loaves are what he loves most to make, ideally in quantities well beyond what any one table needs - he's never quite grasped the concept of baking for one person, and doesn't intend to start. If you ask how long something takes, expect the honest answer: as long as it takes.",
            ],
            'quote' => 'It will be ready when it is ready.',
        ],
        [
            'name' => 'Ilvath Fernglass',
            'image' => '/images/writers/ilvath.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Flower_Fairy_3_(Boston_Public_Library).jpg',
            'domain' => 'Foraged and seasonal cooking - salads, vegetables, and dishes built on a handful of excellent ingredients.',
            'bio' => [
                "Ilvath Fernglass has been cooking with whatever the season actually offers for longer than she's ever specified, and treats the question of her exact age as slightly beside the point. She started, by her account, out of simple respect for good ingredients - an early conviction that a perfect pea doesn't need help becoming more than a perfect pea, and everyone else kept getting in its way. She's held that position ever since, gently and without much interest in being argued out of it.",
                "She measures by season, freshness, and the occasional glance at the moon (purely for the aesthetics, she'll admit, if pressed), keeps a sharp knife and very little patience for a blunt one, and refuses on principle to rename a knife or a chopping board just to sound more mysterious. Salads, foraged greens, and anything that needs almost nothing done to it are what she loves cooking most. She'll tell you when a dish has had enough. Usually before you think it has.",
            ],
            'quote' => 'Do less. Do it precisely.',
        ],
        [
            'name' => 'Morag Saltweather',
            'image' => '/images/writers/morag.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Stories_from_Hans_Andersen_-_Edmund_Dulac_color_plate_at_page_169.jpg',
            'domain' => 'Seafood - fish, shellfish, and anything else drawn from the ocean.',
            'bio' => [
                "Morag Saltweather has been cooking seafood for longer than she'll say, and was - almost certainly - a ship's cook once, on a vessel she doesn't discuss and a crew she discusses even less. She started, as far as anyone's pieced together, somewhere at sea, and never really left it behind; land food still strikes her as a slightly suspicious idea. What we know for certain is that she's terse, she's superstitious, and she's very rarely wrong about fish.",
                "She never names the fish while it's still in the pan, measures doneness against the tide rather than the clock, and has one unforgivable verdict for over-salting: \"you've salted the sea.\" Simply prepared fish and shellfish are what she loves cooking most, treated with just enough interference to make them edible and no more. Ask her why a technique works and she'll tell you. Ask her about her past, and you'll get silence and a very level stare.",
            ],
            'quote' => "Cod is an honest fish. It doesn't need much.",
        ],
        [
            'name' => 'Kessa Ember-Tongue',
            'image' => '/images/writers/kessa.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Elihu_Vedder_-_Fisherman_and_the_Genie_-_06.2431_-_Museum_of_Fine_Arts.jpg',
            'domain' => 'Street food - spiced, fried, fast, and loud.',
            'bio' => [
                "Kessa Ember-Tongue has been cooking - and, more importantly, trading - in markets for as long as there have been markets worth trading in, and treats the two as basically the same skill. She started, by her own telling, because she was good at both fire and a bargain, and it seemed wasteful not to combine them. She has crossed deserts for a single pepper and once, memorably, traded a camel for a jar of sauce. She won't say what happened to the camel.",
                "She measures spice in \"coin-weights,\" treats every instruction like a deal being closed, and genuinely believes a dish isn't finished until you'd smell it from three stalls away. Fast, hot, heavily spiced street food is what she loves cooking most, and she has no patience whatsoever for a cold pan or a slow customer. Everything, to Kessa, is negotiable. Except dinner. Dinner happens now.",
            ],
            'quote' => 'Everything is negotiable. Except dinner.',
        ],
        [
            'name' => 'Grett Underbridge',
            'image' => '/images/writers/grett.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Troll_at_the_door_by_John_Bauer_1914.jpg',
            'domain' => 'Soups and stews - hearty, cheap, one-pot food that feeds whoever\'s there.',
            'bio' => [
                "Grett Underbridge has been cooking, cheaply and in large quantities, for longer than he's willing to discuss, under a bridge he's fairly sure he's occupied for generations. He started, as far as we understand it, out of simple necessity - food was expensive, he wasn't, and it turns out a troll with patience can make very little go a very long way. He'll deny, if asked, that any of this makes him kind.",
                "Everything goes in what he calls simply \"the Large Pot,\" doneness is judged with his signature \"you'll know,\" and an offer of more food always somehow sounds like an inconvenience to him rather than a gift. Soups, stews, and anything that stretches a handful of cheap ingredients into a proper meal are what he loves cooking most. He will grumble the entire time he's feeding you an extra bowl, and he will absolutely still put the bowl down.",
            ],
            'quote' => "Sit down. Eat. You'll know when it's ready.",
        ],
        [
            'name' => 'Bryony Thistledown',
            'image' => '/images/writers/bryony.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Goble-Book_of_Fairy_Poetry129Two-fairies-in-a-garden.jpg',
            'domain' => 'Delicate desserts and breakfasts - anything that lives or dies on a single precise second.',
            'bio' => [
                "Bryony Thistledown has been cooking - extremely quickly - for as long as she can recall, which admittedly isn't very reliable, since she recalls most things at high speed and in the wrong order. Before this, by her own account, she was a tooth fairy, a job she describes as excellent training for caramel: both, she says, are about not looking away at the wrong second. She started cooking, more or less, the moment she realised sugar changes state faster than most people can react to.",
                "She measures time in seconds rather than minutes, calls the precise finishing instant \"the Spark,\" and considers \"about five minutes\" a personal insult. Delicate desserts, breakfasts, and anything with one exact perfect moment are what she loves cooking most - she is, by her own admission, mildly terrified you'll miss it, and mildly delighted every time you don't. Do not blink. She's told you this already.",
            ],
            'quote' => "That's the spark. Pull it. You got it.",
        ],
        [
            'name' => 'the Concierge',
            'image' => '/images/writers/concierge.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Anthony_van_Dyck_-_Self_portrait.jpg',
            // Plain em-dash character, not the &mdash; entity -- this value
            // is rendered through Twig's auto-escaping {{ writer.domain }},
            // so an HTML entity here would show up literally as text.
            'domain' => 'Drinks - cocktails, wine, spirits, tea, mead, punch, and anything else worth pouring slowly.',
            // One array entry per paragraph -- simpler and more robust than
            // a single string the template has to split on blank lines.
            'bio' => [
                "Nobody on staff can confirm the Concierge's real name, birth century, or country of origin, and at this point we've stopped trying. He introduces himself differently every single time - a different title, a different fallen court, occasionally a flatly contradictory account of where he was standing during the same historical disaster. We call him the Concierge regardless, because he can, allegedly, get you absolutely anything, including, eventually, the drink you actually asked for.",
                "What we can confirm: he has never once handed over a recipe without a story attached to it first, he refers to ice as \"winter, bottled,\" and he has strong, oddly specific opinions about the correct way to hold a glass. Take a seat. He's already pouring.",
            ],
            'quote' => 'Patience is the only ingredient I ever insist on.',
        ],
        [
            'name' => 'Fennick Merrymead',
            'image' => '/images/writers/fennick.jpg',
            'imageAlt' => 'https://commons.wikimedia.org/wiki/File:Böcklin_-_Faun,_die_Syrinx_blasend,_um_1875,_8741,_Pinakothek_München.jpg',
            'domain' => 'Celebration - feasts, holidays, banquets, and anything meant to be shared.',
            'bio' => [
                "Fennick Merrymead has been cooking for celebrations for as long as there have been things worth celebrating, which, in his estimation, is always. He started, near as anyone can tell, at the first gathering he ever attended, and simply never stopped treating every meal since as one. He does not believe in cooking for one person - in his telling, there is always a hall, and it is, somehow, always full.",
                "Every recipe of his begins with a toast, the table is always \"the Long Table,\" and his measure for a portion is \"enough, and then twice enough,\" which he means completely literally. Feast dishes, celebration bakes, and anything meant to be torn apart and shared among a crowd are what he loves cooking most. An uninvited guest never troubles him - there's always room, there's always more, and there is, somehow, always cause to raise another glass.",
            ],
            'quote' => 'There is always room at the table.',
        ],
    ];
}
