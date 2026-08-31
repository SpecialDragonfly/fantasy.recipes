# Fantasy Recipes — Architecture

Companion to `spec.md`. This covers the technical stack and how the pieces
fit together; `spec.md` remains the source of truth for domain model and
product decisions.

---

## Stack Overview

- **Runtime:** PHP 8.4+ (`php:8.5-fpm-alpine`) behind Nginx (`nginx:alpine`).
  Alpine + fpm chosen for footprint; both images publish `linux/arm/v7`
  multi-arch builds, which matters because the target hardware is a
  **32-bit ARM (Raspberry Pi, 32-bit OS)**. Originally 8.2, bumped after
  `composer.lock` (resolved against the dev machine's actual PHP 8.5, with
  no `platform.php` pin in `composer.json`) locked `robmorgan/phinx`'s
  transitive symfony/* dependencies to versions requiring PHP >=8.4.1 —
  confirmed 8.5-fpm-alpine still publishes an arm/v7 image before making
  the switch, so the Pi target is unaffected.
- **Database:** MySQL, running **on the host, outside Docker** — the instance
  already exists (serving other things too, presumably). The database for
  this app does not exist yet and is created via Phinx migrations. Containers
  reach it over the network, not via a Docker Compose `mysql` service.
- **Local/test database:** SQLite, swapped in via config for running
  PHPUnit locally without a MySQL dependency.
- **Migrations:** Phinx — a **dev/deploy-only tool**, in `require-dev`, not
  the runtime image. The app itself never touches Phinx (thin PDO
  repositories only), and Phinx 0.16 can't run on the 32-bit ARM target at
  all: it requires composer's `php-64bit` platform package, and its
  14-digit `YYYYMMDDHHMMSS` migration versions overflow `PHP_INT_MAX` on
  32-bit PHP. Migrations are run from a 64-bit machine against the target DB
  (see Deployment & Networking).
- **Linting:** PHPStan.
- **Tests:** PHPUnit.
- **Web framework:** Slim 4 (PSR-7/PSR-15), not a full-stack framework.
- **Frontend:** vanilla HTML/CSS/JS — no build step, no JS framework.
- **Containerization:** Docker Compose, PHP + Nginx services only (MySQL is
  external, per above).

---

## Application Architecture

- **DI container:** `php-di/php-di` — Slim's recommended autowiring
  container, keeps constructor injection lightweight without a heavier
  framework's service-container conventions.
- **Templating:** `slim/twig-view` + Twig. Auto-escaping matters here
  specifically because Story and StorySubmission bodies are user-submitted
  text rendered back to other users — this is the app's main XSS surface.
- **CSRF:** `slim/csrf` middleware on every state-changing route (register,
  login, story submission, admin publish/edit/tag/approve actions).
- **Auth:** `password_hash()` / `password_verify()` with `PASSWORD_DEFAULT`
  (bcrypt). Argon2 variants avoided deliberately — libargon2 availability on
  Alpine/ARM32 base images isn't guaranteed, bcrypt is universally available
  and sufficient here.
- **Sessions:** native PHP sessions to start, file-backed on a persisted
  Docker volume so a container restart doesn't drop active sessions. This is
  fine as long as there's a single app instance (true for a personal-project
  Pi deployment). If this ever runs multiple replicas, swap in a DB-backed
  session handler — noted here as the upgrade path, not built now.
- **Authorization:** Slim route groups + middleware reading role
  (guest/user/admin) off the session, matching the flat three-tier model in
  `spec.md`. No moderator tier, no per-resource ACLs.
- **Data layer:** no ORM. Thin PDO repository classes per aggregate
  (`RecipeRepository`, `StoryRepository`, `TagRepository`, etc.),
  prepared statements throughout, `PDO::ATTR_EMULATE_PREPARES = false`,
  `utf8mb4` charset/collation.
- **HTTP client:** `guzzlehttp/guzzle`, used for outbound calls to the
  Anthropic Claude API.
- **Config:** `vlucas/phpdotenv`. Separate `.env` profiles for
  Docker/MySQL vs. local/SQLite. Canonical app domain is an env var (see
  Deployment & Networking) — never hardcoded.
- **Mail:** `symfony/mailer` over **Resend** (`resend+api://` DSN,
  `symfony/resend-mailer` + `symfony/http-client`), behind the app's own
  `App\Mail\Mailer` interface. `App\Mail\SymfonyMailer` is the real
  implementation; `App\Mail\LogMailer` (writes to `storage/mail/`) is the
  fallback whenever `MAIL_API_KEY` is unset -- i.e. local dev and the whole
  test suite, so neither needs a provider or network. The DSN is built in
  `src/bootstrap.php` from `MAIL_API_KEY` (a Resend "Sending access" key);
  swapping providers is a one-line DSN change plus the matching
  `symfony/*-mailer` bridge. `MAIL_FROM_ADDRESS` must be on a domain
  verified in Resend (`fantasyrecipes.co.uk`, via its DKIM/SPF DNS
  records). Used for **password reset only** -- this is deliberately the
  one place the app sends email; the spec explicitly rules out
  submission-status notifications elsewhere.
  A `password_reset_tokens` table (`user_id`, `token_hash`, `expires_at`)
  backs the flow: generate a single-use, expiring token, email a link
  containing it, verify + consume it, expire any unused prior tokens for
  that user on request.
- **Package versioning:** `composer.json` uses caret (`^`) constraints on
  stable major versions per dependency; `composer.lock` is committed so
  builds are reproducible across dev machine, CI (if added later), and the
  Pi.

---

## CLI Commands

`symfony/console` provides the command layer — already a transitive
dependency of Phinx, so it shares bootstrap/DI with the web app rather than
being a second, disconnected toolchain.

- **`recipe:import <url>`** — imports one recipe from one source URL,
  admin-initiated (see `ideas.md`; replaces an earlier bulk multi-site
  discovery scraper that no longer exists). A thin wrapper around
  `App\Scraping\RecipeImporter`, the same class the admin web UI's "Import a
  recipe" form (`GET`/`POST /admin/recipes/import`) calls — the CLI and the
  web form are two entry points onto one fetch/extract/validate pipeline,
  not two implementations. Fetches the page, extracts schema.org JSON-LD
  Recipe data, and writes a single `recipes` row at `status = 'draft'` with
  `OriginalIngredients`/`OriginalInstructions` filled in. No staging table —
  the row created is the same row an admin then rewrites/tags/translates/
  publishes in place. Confirmed working against BBC Good Food, HelloFresh
  (UK), and Riverford (see `RecipeImporter`'s docblock for the per-site
  JSON-LD quirks handled along the way — Riverford nests `recipeIngredient`
  in an extra array level, HelloFresh packs lettered sub-steps into one
  HTML blob per step), but the extractor is generic schema.org JSON-LD
  parsing, not bespoke per-site scraping — other sites with the same
  structured data work too, and one without it fails cleanly rather than
  falling back to guessed HTML scraping — the admin web UI sends that
  failure (plus a robots.txt disallow or a fetch/HTTP error) on to
  `/admin/recipes/import/manual`: the same recipe-creation fields as manual
  entry, with the source page open in an iframe alongside so the admin can
  copy it across by hand. Not every site allows being framed this way (BBC
  Good Food and Riverford both send `X-Frame-Options: SAMEORIGIN`, so their
  iframe renders blank) — the page also has an "open in a new tab" link
  that works regardless. An "already imported" failure (the dedupe check,
  not an extraction failure) stays on the plain import form instead, where
  ticking "force" retries the same automated import.
- **`recipe:translate-draft`** — batch job that calls the Claude API for
  every recipe still missing a `NarratorRecipe`, writing one (and a matching
  Story) in the assigned Narrator's voice. Run as a batch across many
  recipes at once (fits "thousands of recipes" much better than a per-recipe
  web button), with admin review happening afterward through the normal web
  UI. **Not implemented yet** — still a stub.
- **`recipe:normalize-temperatures`** — batch job rewriting every recipe's
  `OriginalInstructions`/`NarratorRecipe` temperature mentions to a
  consistent "Celsius (Fahrenheit)" order.
- **`mail:enqueue-recipe-notifications`** — daily cron. Rolls every
  published recipe with `notified_at IS NULL` into one queued campaign:
  `single` for exactly one, `digest` for more (see the mail queue below).
  Also the admin "Check now" button.
- **`mail:send-queue`** — daily cron, shortly after the enqueue job. Sends
  every campaign in `recipe_email_queue` whose `scheduled_for` has passed.
  A delivery error stops the campaign (`status = failed`, `last_error` set);
  the remaining deliveries stay `pending` so re-running, or the admin
  "Retry" button, resumes from where it stopped. Also driven per-campaign
  by the admin "Send now" / "Retry" buttons.

**Recipe-email queue** (`db/migrations/20260831150000…`,
`App\Mail\RecipeNotifications`, admin at `/admin/mail-queue`): a campaign
row plus one `recipe_email_queue_deliveries` row per recipient (snapshotted
at enqueue time). Purpose-built as a dead-letter queue -- provider rate
limits leave failed/unsent deliveries visible and resumable. The admin list
row opens a per-recipient view (`/admin/mail-queue/{id}`) with a "Send"
button on each pending/failed delivery, for re-driving one stuck address
without touching the rest (`RecipeNotifications::sendDelivery`); clearing
the last outstanding row closes the campaign out as `sent`. Every
marketing email carries a `List-Unsubscribe` / `List-Unsubscribe-Post`
header (RFC 8058) and a footer link to `/unsubscribe?u=<users.unsubscribe_token>`
(no login).

Batch commands are designed to be safely interruptible and re-runnable: each
operates by selecting rows still "not yet done" for that command and writing
back to a row immediately after it succeeds rather than batching updates at
the end. Killing the process partway through just leaves the remaining rows
untouched — the next invocation picks up where it left off with no separate
resume/checkpoint mechanism needed.

---

## Data Model Notes

Implementation-level additions:

- `recipes.status ENUM` → avoided in favor of `VARCHAR` + application-level
  validation (see Testing Strategy below for why: Phinx migrations need to
  run against both MySQL and SQLite, and `ENUM` doesn't translate).
- `recipes.story_id` is deliberately NOT a real `FOREIGN KEY` — `stories`
  has its own `recipe_id` FK pointing the other way, and a table can't hold
  a live circular FK against a table that doesn't exist yet in the same
  migration set. Referential integrity here is application-level only
  (`RecipeRepository`/`StoryRepository`).
- `stories.archived_at` (nullable) absorbs what used to be a separate
  `story_archive` table: a recipe can have many `stories` rows over time,
  but only one — the one `recipes.story_id` points at — has `archived_at
  IS NULL`. Every other row for that `recipe_id` is history, kept for
  possible reversion.
- There is no `images` table / recipe image upload feature currently — it
  was removed as part of the recipes-table redesign and would need to be
  rebuilt as its own piece of work if wanted again.
- `users.marketing_opt_in` / `marketing_opt_in_at` / `unsubscribe_token`
  (migration `20260831…`): first-party marketing-email consent only
  ("email me when a new recipe is published"). Opt-in defaults to false
  and is an active tick at registration or on `/account` — never assumed
  (UK GDPR/PECR); `marketing_opt_in_at` records when consent was last
  given so it can be demonstrated, and is nulled on opt-out. Every user
  gets an `unsubscribe_token` (generated in `UserRepository::create`) for
  a future no-login opt-out link — the marketing email itself, and the
  route that consumes the token, aren't built yet. Data is not shared with
  third parties.

---

## Search

- `RecipeSearch` interface, with `MysqlFulltextSearch` as the production
  implementation (`MATCH(title, original_ingredients, original_instructions,
  narrator_recipe) AGAINST (... IN NATURAL LANGUAGE MODE)`), backed by a
  `FULLTEXT INDEX` on those four columns.
- Unit tests stub `RecipeSearch` — no database involved.
- A separate integration test suite exercises the real
  `MysqlFulltextSearch` implementation against an actual MySQL instance.
  SQLite has no FULLTEXT equivalent, so it's never asked to run this code
  path — see Testing Strategy.

---

## SEO & Security Headers

- **Recipe JSON-LD**: every published recipe's detail page embeds a
  schema.org Recipe `<script type="application/ld+json">` block
  (`App\Http\RecipeJsonLd`, rendered via `layout.twig`'s `head_extra`
  block) — the same structured-data shape `JsonLdRecipeExtractor` reads
  back out of other sites on import, mapped in reverse. `recipeIngredient`/
  `recipeInstructions` come from OriginalIngredients/OriginalInstructions
  (the mundane truth, already uncollapsed in the same page's HTML — see
  Immersion Rules in spec.md), not `NarratorRecipe`; `name` is still the
  fantasy `Title`, since that's the headline search results actually show.
  No `image` property — there's no recipe image feature. Encoded with
  `JSON_HEX_TAG` so admin-entered text containing a literal `</script>`
  can't break out of the tag.
- **`X-Frame-Options: SAMEORIGIN`** on every response
  (`App\Http\Middleware\SecurityHeadersMiddleware`), the same clickjacking
  protection BBC Good Food and Riverford use — and the reason their own
  pages show blank in this app's admin "Import a recipe" iframe preview.
  Registered directly in `public/index.php`, after `addErrorMiddleware()`
  rather than in `src/middleware.php`, so it's the true outermost layer
  (Slim's middleware stack is LIFO) and lands on every response including
  one built by the error middleware for an unhandled exception.

---

## Deployment & Networking

- **Domain:** branding name is `fantasy.recipes`; initial hosting is at
  `recipes.notquitehuman.co.uk` on an existing personal webserver. The
  canonical domain is an env var, not hardcoded, so switching later is a
  config/DNS change only.
- **Existing reverse proxy:** the host already runs its own nginx as a
  reverse proxy in front of multiple sites. This app's own Nginx container
  therefore does **not** face the internet directly — it listens on an
  internal port, and the host's existing reverse proxy forwards
  `recipes.notquitehuman.co.uk` traffic to it.
- **TLS termination happens at the host's existing reverse proxy**, not in
  this app's stack. This app's Nginx container has no cert management
  (no Certbot/Let's Encrypt config) — that's out of scope here.
- **Trusted proxy headers:** because there's a proxy in front of a proxy
  (host nginx → container nginx → php-fpm), `X-Forwarded-For` /
  `X-Forwarded-Proto` need to be trusted and passed through correctly at
  each hop, or Slim/PHP will see the wrong client IP and think every
  request is plain HTTP. This affects: session cookie `secure` flag logic,
  any IP-based logging, and CSRF/same-origin checks. Needs explicit
  `set_real_ip_from` / `X-Forwarded-*` handling in the container's Nginx
  config, and Slim configured to trust that proxy.
- **Docker networking to host MySQL:** on Linux Docker Engine (not Docker
  Desktop), `host.docker.internal` is not resolved by default. Needs
  `extra_hosts: ["host.docker.internal:host-gateway"]` in Compose, or the
  host's actual docker0/bridge IP, wired into the DB DSN via `.env`.
- **Deploy process:** manual — SSH into the Pi, `git pull`, then
  `docker compose up -d --build`. No CI infrastructure, no image registry.
  Deliberately the simplest possible flow for a personal project; revisit
  only if build time on the Pi's own limited CPU becomes painful enough to
  justify building images elsewhere and pulling instead.
- **Migrations at deploy time:** *not* `docker compose exec app vendor/bin/phinx`
  — Phinx isn't in the runtime image and wouldn't run on 32-bit anyway (see
  Migrations, above). Run them from a 64-bit box against the Pi's MariaDB
  over an SSH tunnel:
  ```
  ssh -f -N -L 13306:127.0.0.1:3306 <pi>
  # local .env with DB_HOST=127.0.0.1, DB_PORT=13306, prod DB_DATABASE/USER/PASSWORD
  vendor/bin/phinx migrate -e production
  ```
  The Pi's MariaDB runs on the host (not in Docker) and listens on
  `0.0.0.0:3306`; the tunnel avoids exposing it more widely.
- **Server-local compose override:** `docker-compose.override.yml` on the Pi
  (untracked) drops the dev `./:/app` bind mount so the container runs the
  image-baked code + `vendor` + www-data-owned `storage` (otherwise the
  mount shadows `/app/vendor` and Twig can't write its cache), and joins the
  `web` container to the existing reverse proxy's `websites_mynet` network.
- **Reverse proxy vhost:** `nginx/conf.d/fantasyrecipes.conf` in the
  host's `~/Projects/websites` stack — `proxy_pass` to the
  `fantasyrecipes_web` container by name over `websites_mynet`, TLS
  terminated at that proxy (Let's Encrypt, renewed by its `renew-cert.sh`
  cron). `www` 301s to the apex, which is canonical (`APP_URL`).

---

## Testing Strategy

- **Unit tests (PHPUnit):** run against SQLite locally, no external
  dependencies. `RecipeSearch` is stubbed rather than exercised for real —
  see Search above.
- **Migration portability gotcha:** Phinx migrations must run against both
  MySQL (prod) and SQLite (local tests). Three MySQL-specific things don't
  translate:
  - `ENUM` columns → use `VARCHAR` + app-level validation instead (also
    avoids the "adding an enum value is a migration" pain long-term).
  - `FULLTEXT INDEX` → skip the statement when the Phinx adapter is
    `sqlite` (`$this->getAdapter()->getAdapterType() === 'mysql'` guard in
    the migration).
  - **FK column signedness** → Phinx's default `id` primary key is
    `INT UNSIGNED` on MySQL, so every foreign-key column must be
    `addColumn('...', 'integer', ['signed' => false])` or `addForeignKey`
    fails with `errno 150`. SQLite ignores signedness, so the local suite
    won't catch a mismatch — it only shows up against a real MySQL.
- **Integration tests:** a smaller, separate suite that requires a real
  MySQL instance — covers `MysqlFulltextSearch` and anything else that's
  genuinely MySQL-specific. Not part of the fast local SQLite-backed suite.
- **Static analysis:** PHPStan, run across the whole codebase including CLI
  commands.

---

## Open Questions

_(none outstanding)_

