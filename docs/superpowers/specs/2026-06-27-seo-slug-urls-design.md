# SEO-friendly slug URLs for project detail pages

**Date:** 2026-06-27
**Status:** Design — pending implementation plan

## Goal

Every roadwork detail page is reachable at a single-segment, SEO-friendly URL
made of `{municipality}-{slugified-title}`, e.g.

```
/s-gravenhage-gas-hoofdstraat
```

Routing is exactly **one level deep**: `/{slug}` is the detail page. Normal
404 behaviour and all existing routes (`/`, `/kaart`, `/api/*`, `/tiles/*`)
keep working. Old slugs **301-redirect** to the current slug.

## Decisions (locked)

| Question | Decision | Why |
|---|---|---|
| Slug stability | **Regenerate on title change + keep history** | Title is derived from the NDW/DATEX feed (`causeDescription`) and re-upserted, so it can change. Old URLs must not die. |
| Collisions | **Counter only on collision** (`...-2`, `...-3`) | Same gemeente + same cause can repeat. First gets the clean slug. |
| Redirects | **Build now** (lightweight, via the slug table) | History is dead weight without a consumer; the redirect *is* the consumer. The slug table gives us both for free. |
| Storage | **Dedicated `roadwork_slugs` table**, not a column | See below. |
| Indexing | **DB unique index on the slug** + **slug added to the Meilisearch document** | Fast `/{slug}` lookup + 404 distinction; map/search links come from Meili, not the DB. |

## Why a slug table, not a column

1. `roadworks` is a **temporal table** — the `sys_period` trigger versions every
   content change. A slug column would churn version history on every slug change.
2. History/redirects are **many slugs → one roadwork**; a single column can't
   hold the old ones.
3. Routing is a separate concern from the mirrored feed data. Keeping it in its
   own non-versioned table keeps the upsert path clean.

### Schema: `roadwork_slugs`

```
id           bigint PK
roadwork_id  bigint FK -> roadworks(id) ON DELETE CASCADE
slug         varchar  UNIQUE  (indexed)
is_current   boolean  default false
created_at   timestamptz
```

- The **current** slug for a roadwork = its row where `is_current = true`
  (at most one per roadwork — enforced by a partial unique index on
  `(roadwork_id) WHERE is_current`).
- Any other row is a **historical** slug that 301s to the current one.
- This table is **not** versioned (no `sys_period` trigger).

## Slug generation

A single `RoadworkSlugger` service produces the slug from
`municipality` + `title`:

- **municipality** — from `road_authority`, strip a leading `Gemeente ` /
  `Provincie ` prefix, then `Str::slug()`. `Gemeente 's-Gravenhage` →
  `s-gravenhage`. Null authority → `nederland`.
- **title** — the **same** derivation used on the detail page and cards
  (last comma-separated part of `causeDescription`, with the existing
  fallback). See refactor below.
- Combine: `Str::slug("{municipality} {title}")`.
- **Uniqueness**: if the base slug already belongs to a *different* roadwork
  (current or historical), append `-2`, `-3`, … until free. A roadwork keeping
  its own existing slug is a no-op.

### Shared title helper (targeted refactor)

`ProjectDetail::title()` and `RoadworkCard::title()` contain the **same**
`causeDescription` parsing. The slug must match the displayed title, so extract
this into one helper (e.g. `App\Roadworks\RoadworkTitle::for(Roadwork): string`
plus the shared `descriptionParts()`), and have both DTOs and the slugger use
it. No behaviour change — just deduplication so the slug can't drift from the title.

## Keeping slugs in sync

The feed flows through `RoadworkUpserter::upsert()` (raw SQL, returns
`inserted` bool). Extend it to also return the row `id`, then after the upsert
call the slugger:

1. Compute the desired slug for the row.
2. If the roadwork has no current slug, or its current slug differs:
   - mark the existing current row `is_current = false` (becomes a redirect),
   - insert/activate the desired slug as the new `is_current = true` row
     (reusing a historical row for that exact slug if one exists).
3. Otherwise no-op.

This runs inside the same import path, so every imported roadwork ends with
exactly one current slug.

### Backfill

An artisan command (e.g. `roadworks:backfill-slugs`) generates current slugs
for all existing rows, idempotently (safe to re-run). Run once on deploy.

## Routing & resolution

`routes/web.php` — register the catch-all **last** so the named routes win
(Laravel matches in registration order):

```php
Route::get('/{slug}', [ProjectController::class, 'showBySlug'])
    ->where('slug', '[a-z0-9-]+')
    ->name('projecten.show');
```

`ProjectController::showBySlug(string $slug)`:

1. Find a `roadwork_slugs` row with this slug (with its roadwork).
2. Not found → `abort(404)` (normal 404 preserved).
3. Found and `is_current` → render `Projecten/Show` (unchanged DTO + view).
4. Found but **not** current → permanent **301** to the roadwork's current
   slug, e.g. `redirect()->route('projecten.show', $currentSlug, 301)`.

The existing `/projecten/{id}` route is **kept** as a permanent redirect to the
current slug, so old numeric bookmarks/links survive.

## Surfacing the slug to the frontend

- **`ProjectDetail`** + **`RoadworkCard`** DTOs gain a `slug` field (current slug).
- **Meilisearch**: `Roadwork::toSearchableArray()` adds `'slug'`. The model gets
  a `currentSlug` relation/accessor; `makeAllSearchableUsing()` eager-loads it so
  `scout:import` issues no per-row query. `RoadworkSearchController::toFeatures()`
  adds `slug` to each feature's `properties`.
- **Links** switch from `/projecten/${id}` to `/${slug}`:
  - `resources/js/pages/Home.vue` (featured + grid),
  - `resources/js/pages/Kaart.vue` (map detail panel "Bekijk project").
- Regenerate Wayfinder (`projecten.show` now takes `slug`).

## Testing

- **Slugger**: format (prefix strip, diacritics, null authority), collision →
  `-2`/`-3`, keeping own slug is a no-op.
- **Sync on upsert**: title change creates a new current slug and demotes the
  old one to a redirect; unchanged title no-ops.
- **Routing**: current slug → 200 + correct project; historical slug → 301 to
  current; unknown slug → 404; reserved paths (`/kaart`) still resolve; old
  `/projecten/{id}` → 301.
- **Backfill**: command assigns one current slug per existing roadwork; re-run
  is idempotent.
- **Meili**: slug present in the indexed document and in search feature props
  (follow the project's `SCOUT_PREFIX` test-isolation convention).

## Out of scope

- Manual/editorial slug overrides.
- Per-locale slugs.
- Sitemap generation (separate task; this design makes it trivial later).
