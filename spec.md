# Fantasy Recipes — Spec

## Concept

A recipe website where every recipe is framed as an in-world fantasy ritual. A
narrator (a witch, a dragon, or anything else a contributor invents) tells the
story of how they came to make the dish, and the instructions are written as
ritual prose ("preheat the kiln to 200C," "in a shallow vessel over a strong
flame, cook the ground cattle meat") rather than standard recipe copy. The
plain, functional version of the recipe still exists underneath, one
deliberate click away, for anyone who needs it.

Visual identity: a warm, dim, candlelit medieval fantasy tavern — think the
Prancing Pony (LOTR), a Baldur's Gate 3 tavern interior, or a Witcher 3 inn.

Initial audience: personal project, pointed at friends and family first. Might
never go wider than that. Registration is still open self-serve regardless.

---

## Domain Model

**Recipe**
The single row backing a recipe's entire lifecycle — import, rewrite,
narrator translation, admin review, publish — replacing the earlier
RecipePage/InstructionSet split (they were always 1:1, so keeping them as
two tables just meant two lookups for one thing). Has no mundane/plain title
anywhere in the UI once published — only the in-world fantasy name is ever
displayed (see Immersion Rules); `Title` starts as whatever plain title an
import found and is overwritten in place by an admin before publish. No
ingredient-quantity scaling (2x/0.5x servings) — recipes are fixed-quantity,
free-form prose, not structured data. Holds:
- `Origin` — where this recipe came from: a source URL for an imported
  recipe, or a plain description for a manually-entered one (see Content
  Pipeline). Optional, free text, not required to be a real URL.
- `OriginalIngredients` / `OriginalInstructions` — the mundane truth.
  Ingredients are carried through close to verbatim (a list of ingredients
  is a fact, not an expression); instructions are rewritten by an admin in
  their own words from the raw imported material — **never** copied
  verbatim from a source site.
- `Narrator` — the character voicing this recipe's ritual-styled prose and
  its Story (see Narrator below). Assigned once a recipe is ready for its
  narrative pass.
- `NarratorRecipe` — the ritual-styled version of the instructions, in the
  assigned Narrator's voice. Hand-crafted prose, AI-drafted first from
  `OriginalInstructions`, then reviewed/edited by an admin before
  publishing. Format is flexible (prose, verse/poem form, etc.) at the
  admin/AI's discretion.
- `Status` — `draft` / `published`. A recipe is a draft through import,
  rewrite, narrator translation, and admin review alike — all of that
  happens in place on the same row — until an admin publishes it. There is
  no separate drafted/reviewed distinction on the text itself; it's just
  editable content on a draft recipe.
- one current **Story** (see below).

**Story**
The narrative anecdote (a witch's tale, a dragon's tale, etc.) told for a
Recipe. A Recipe can have many Stories over time — only one is current/live;
overwriting it (a deliberate re-draft, not a small correction) archives the
previous one with an archival timestamp instead of deleting it, for possible
reversion. Archived Stories are admin-only, never shown to users. Currently
admin-authored only — there is no public submission/moderation flow (see
Roles & Permissions).

**Narrator**
The fantasy character voicing a Recipe's Story and NarratorRecipe. Fully
open-ended — no fixed roster (see `personas.md` for the curated house
roster used as a starting set of voices).

**Tag**
A single, homogeneous tag type covering both functional/navigational tags
(Starter, Main, Dessert, Soup, Hors d'oeuvre, ...) and whimsical/easter-egg
tags (e.g. "Love potion," "Only on full moon" for witch recipes). Many-to-many
with Recipe. Bias toward over-tagging rather than under-tagging. Admins
can create, rename, delete tags, and change which tags apply to a given
recipe. **Merge** (combining duplicate/synonymous tags and reassigning
affected recipes) is explicitly deferred, not v1.

**Wishlist ("Grimoire")**
A single per-user bookmark list — "recipes I want to try." No separate
"already made this" tracking.

**Published**
The `published` state of a Recipe's `Status`, gating public visibility.
Toggled by an admin once they're satisfied with the `NarratorRecipe`.

---

## Roles & Permissions

Three flat tiers, no moderator tier:

- **Guest** — full read access to everything published (OriginalIngredients,
  OriginalInstructions, NarratorRecipe, Story — no content gating at all).
  Cannot submit anything. No Grimoire/wishlist.
- **Logged-in User** — guest rights, plus: use the Grimoire wishlist.
  Registration is **open self-serve** — anyone who finds the URL can sign up
  with no invite code or approval step. There is currently no public
  submission path for Stories — Story is admin-authored only (see Domain
  Model: Story).
- **Admin** — flat, equal power across all admins: import recipes, edit
  OriginalIngredients/OriginalInstructions/NarratorRecipe/Story,
  publish/unpublish, manage tags.

Multi-admin editing conflicts (two admins picking up the same item at once)
are an accepted, tolerable risk — no locking/reservation system.

---

## Content Pipeline (Building the Corpus)

1. **Import** - admin pastes a single recipe page's URL into the admin
   UI's "Import a recipe" form (or runs `recipe:import <url>` on the CLI,
   the same pipeline either way -- see architecture.md), or enters a
   recipe by hand with no source URL at all. No mass scraping of recipes,
   one recipe per import. Confirmed working against BBC Good Food,
   HelloFresh, and Riverford, though it isn't limited to just those three --
   any site embedding schema.org recipe data works the same way. Lands as a
   Recipe row at `Status = draft`, with `OriginalIngredients`/
   `OriginalInstructions` filled from the raw import.
2. **Rewrite** - `OriginalInstructions` is rewritten in the admin's own
   words, manually assessed for copyright-infringing aspects.
   `OriginalIngredients` is generally carried through close to verbatim (a
   list of ingredients is a fact, not an expression).
3. **Translate** - A Narrator is assigned to the recipe. A manual call of
   Claude then drafts a Story and, separately, writes `NarratorRecipe` in
   the same voice. Both are just text on the still-`draft` recipe at this
   point — there's no separate drafted/reviewed status to track.
4. **Review & Publish** — an admin reviews/edits `NarratorRecipe` and the
   Story, tags the recipe, and toggles `Status` to `published`.

---

## Tone & Worldbuilding

- Primary references: the Prancing Pony (LOTR), Baldur's Gate 3 tavern
  interiors, The Witcher 3 inns — warm, cozy, whimsical high-fantasy.
- Mechanically inspired by *Necronomnomnom* (kiln instead of oven, "shallow
  receptacle" instead of pan, poem-form recipes) but explicitly **not** its
  dark/occult-horror register (no "sacrificing" or "lobotomizing"
  ingredients). Stays witch-and-dragon whimsical, not gallows humor.
- No enforced cross-recipe ritual lexicon — artistic license per
  recipe/narrator. "Oven" doesn't have to always translate the same way.
- Ritual text can wink at the mundane substitute inline as a joke (e.g. "eye
  of newt, or peas if you don't have them").

---

## Immersion Rules

- **Content is fantasy-only.** Recipe titles, Story, and NarratorRecipe never
  show a mundane equivalent directly. Immersion is the paramount rule for
  content — this holds even in active kitchen-use scenarios (see below).
- **OriginalIngredients/OriginalInstructions ("the mundane truth")** always
  exist but are collapsed/hidden by default, one click away behind a
  **"Reveal the mundane truth"** toggle.
- **Site chrome is judged case-by-case, not blanket-immersive:**
  - Wishlist → **"Grimoire"** (immersive).
  - Login → stays plain **"Login"** (clarity/usability wins here).
  - Tags → stay plain/functional (Starter/Main/Dessert/Soup) but share the
    same pool as whimsical easter-egg tags.

---

## Naming & Domain

- Branding/project name: **fantasy.recipes**.
- Initially hosted at **recipes.notquitehuman.co.uk** (an existing personal
  webserver with its own nginx reverse proxy) rather than the `fantasy.recipes`
  domain itself. The canonical domain should be config-driven (env var), not
  hardcoded anywhere in the app, so the eventual move to `fantasy.recipes` is
  a DNS/reverse-proxy/config change rather than a code change. See
  Architecture doc for the reverse-proxy/networking implications.

---

## Visual Design

- **Aesthetic references:** the Prancing Pony (LOTR), Baldur's Gate 3 tavern
  interiors, The Witcher 3 inns.
- **Light theme:** wood-effect paneling (dark exposed timber, Tudor/
  half-timbered style) + off-white plastering, parchment tones.
- **Dark theme:** not a dimmed version of the light theme — a second, fully
  realized material world: stone and slate walls, chalk-on-slate lettering.
- **Texture vs. legibility boundary:** immersive wood/stone/timber textures
  belong on the site's *frame* (header, nav, borders, card backgrounds).
  Actual recipe text sits on a clean, high-contrast panel — parchment-and-ink
  in light mode, slate-and-chalk in dark mode — so it stays readable.

---

## Mobile / Kitchen Use

- The immersion-first stance holds even mid-cook: `NarratorRecipe` stays the
  default/primary text at all times. `OriginalIngredients`/
  `OriginalInstructions` stay behind "Reveal the mundane truth" regardless of
  context — if someone needs it with messy hands mid-recipe, that's on them
  for not reading ahead first.
- The screen should stay awake while a recipe page is open (Wake Lock API),
  so it doesn't lock mid-cook with busy/dirty hands.
- Serving-size scaling is explicitly **out of scope**.

