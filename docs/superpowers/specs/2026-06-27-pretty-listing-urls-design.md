# Pretty listing URLs — design

Date: 2026-06-27
Branch: `feat/cbs-gebiedsindelingen-importer`

## Goal

Pretty, hierarchical URLs for the roadworks **listing** (Werkzaamheden), alongside
the existing roadwork **detail** pages. Examples:

- `/amsterdam` → listing filtered to gemeente Amsterdam
- `/noord-holland/amsterdam` → same area, longer form, **301** → `/amsterdam`
- `/amsterdam/gestremd` → Amsterdam + status facet
- `/amsterdam/wegwerkzaamheden/rijkswaterstaat` → area + type + authority

Segments are ordered biggest container → smallest, then facets. The router is
DB-aware (areas + roadworks live in the DB) without registering one route per row.

## Approach

A single catch-all route delegates to a **bidirectional URL mapper** built from
**segment handlers**. Each handler owns both directions:

- `match()` — parse a slice of the path into a `ListingQuery` (or resolve a detail)
- `build()` — emit that segment's canonical string from a `ListingQuery`

An explicit ordered list is the single source of truth for canonical segment
order (parse precedence and build order both derive from it). This mirrors the
proven Symfony `SearchRequestBuilder` pattern the author used previously, with
three fixes (variable-width consume, area-as-one-greedy-handler, instance-scoped
memoization).

We do **not** generate per-area routes (kills route caching, pollutes
`route:list`, thousands of buurten). `route:list` shows one named route; an
`area:urls` artisan command enumerates real URLs from the DB when needed.

## Data model — unified `slugs` table

Holds **resolvable entities only**: roadworks + CBS areas. Facets are NOT rows
(they are code/enum/dynamic-backed handlers).

```
slugs
  id
  slug              string        // single leaf segment: 'amsterdam', 'centrum-buurt', 'a4-knooppunt-x'
  sluggable_type    string        // morph: Roadwork | Provincie | Gemeente | Wijk | Buurt | Landsdeel
  sluggable_id      bigint
  parent_id         bigint  null  // self-FK = STRUCTURAL parent's slug row; null = root-eligible
  is_current        bool          // history: renamed/old-vintage slugs kept, is_current=false → 301
  timestamps

  unique (parent_id, slug) where is_current   // sibling uniqueness; collisions must be resolved at import
  index (sluggable_type, sluggable_id)         // reverse: entity → current slug (build/redirect)
  index (slug)                                 // first-segment / global lookup
```

Notes:

- **`parent_id` = structural parent** (a gemeente's provincie). This makes the
  longer `/noord-holland/amsterdam` form resolvable by walking. It is *not* the
  canonical predecessor.
- **Canonical path is computed, not stored as a string.** `CanonicalPath::for($area)`
  walks `parent_id` upward only as far as needed for uniqueness ("shortest-unique").
  One definition, used by both build and the resolver's redirect check. We do not
  duplicate `'noord-holland/'` as a literal prefix across thousands of rows.
- **`RoadworkSlug` merges into this table.** Roadworks → `parent_id null`, flat
  namespace, `is_current` preserved. Existing 301 (historical → current) becomes
  the generalized mechanism.

### Slug generation (import time)

For each CBS area: slugify `name`, set structural `parent_id`, mark current vintage
`is_current = true`, older vintages `is_current = false`.

**Collision rule (decided): larger area wins the bare slug.** When a wijk *Centrum*
and a buurt *Centrum* share a gemeente parent, the wijk keeps `centrum`; the buurt
becomes `centrum-buurt`. The `unique (parent_id, slug)` index is the safety net —
import fails loudly if two siblings still clash after the rule runs.

## Resolver — narrowing walk

Supports variable entry level (`/amsterdam` and `/noord-holland/amsterdam`).

```
AreaSegment.match(cursor, query):
    candidates ← current slug rows where slug = seg0           // may start at any level (see promotion cap)
    for each subsequent segment segN:
        next ← rows where slug = segN AND parent_id ∈ {candidate ids}
        if next is empty: break        // segN belongs to the next handler (facet/roadwork)
        candidates ← next
    if exactly 1 candidate  → set area on query, consume those segments
    if  > 1 candidate       → ambiguous → 404 / disambiguation
    if    0 candidate       → no match (consume 0)
```

After a successful listing resolve, compute `CanonicalPath::for($area)`; if the
input path ≠ canonical → **301** to canonical. This handles redundant ancestors
(`/noord-holland/amsterdam` → `/amsterdam`) and stale vintages uniformly.

Each step is an indexed `(parent_id, slug)` lookup — cheap and cacheable.

**Root-promotion cap (decided):** promotion is capped at **gemeente**. Provincie,
landsdeel and gemeenten may start a path; wijk/buurt always need ≥ their gemeente
for context. Prevents a globally-unique buurt name squatting a bare root URL.
This rule lives inside `AreaSegment::match()` (handlers self-gate, like the old
`GeneralSegment` gating on `isFirst()`).

## Namespace / layout

All routing classes live under `App\Router`:

- `App\Router\UrlSegment` (interface), `App\Router\SegmentCursor`, `App\Router\ListingUrlMapper`,
  `App\Router\ListingQuery`, `App\Router\CanonicalPath`, `App\Router\UnmatchedSegmentException`.
- Handlers under `App\Router\Segments\` (`AreaSegment`, `StatusSegment`, `TypeSegment`,
  `AuthoritySegment`, `RoadworkSegment`).
- The `Slug` Eloquent model stays in `App\Models`.

## Segment handlers

Path grammar (listing): `[area]? [status]? [type]? [authority]?` + query string
(`q`, `sort`, `page`). Detail: a standalone roadwork slug.

| Handler            | Source   | Backing                                  |
|--------------------|----------|------------------------------------------|
| `AreaSegment`      | DB       | `slugs` (provincie…buurt hierarchy)      |
| `StatusSegment`    | code     | `RoadworkStatus` enum                    |
| `TypeSegment`      | code     | `RoadworkType` enum                      |
| `AuthoritySegment` | dynamic  | distinct `road_authority` slugs (cached) |
| `RoadworkSegment`  | DB       | `slugs` (detail, flat namespace)         |

Canonical `buildOrder`: Area → Status → Type → Authority. (Roadwork detail is its
own standalone URL form.)

### Interface

```php
interface UrlSegment
{
    public function match(SegmentCursor $cursor, ListingQuery $query): int; // segments consumed; 0 = no match
    public function build(ListingQuery $query): ?string;                    // canonical segment(s) or null
}
```

`SegmentCursor` exposes `peek(n)`, `remaining()`, `consume(n)`, `done()`,
`isFirst()` — variable-width consume, unlike the old single-step `next()`.

### Mapper

- `ListingUrlMapper::parse(path): ListingQuery` — first handler to consume per
  cursor position wins; unmatched segment → `UnmatchedSegmentException` (404).
- `ListingUrlMapper::build(ListingQuery): string` — walk `buildOrder`, join
  non-null segments; memoized per `ListingQuery` (pagination builds ~20 links
  sharing area/facet state).

`ListingQuery` is the `SearchRequest`-equivalent DTO; it also drives the existing
Meilisearch `RoadworkSearch::browse()` call in `WerkzaamhedenController`.

### Wiring (tagged services)

```php
$this->app->tag([
    AreaSegment::class, StatusSegment::class, TypeSegment::class,
    AuthoritySegment::class, RoadworkSegment::class,
], 'listing.segments');

$this->app->singleton(ListingUrlMapper::class, fn ($app) => new ListingUrlMapper(
    segments:   iterator_to_array($app->tagged('listing.segments')),
    buildOrder: [AreaSegment::class, StatusSegment::class, TypeSegment::class, AuthoritySegment::class],
));
```

## Routing

```php
// stays LAST; replaces the current /{slug} detail route
Route::get('/{path}', ListingController::class)
    ->where('path', '[a-z0-9-]+(?:/[a-z0-9-]+)*')
    ->name('listing');
```

Static routes (`/`, `/kaart`, `/werkzaamheden`, `/api/*`, `/tiles/*`,
`/projecten/{id}`) remain defined above and win. The controller calls
`$mapper->parse($path)`; if it resolves to a detail → render detail; if a listing
→ render Werkzaamheden with the `ListingQuery`; on canonical mismatch → 301.

## Migration

1. Create `slugs` table.
2. Data-migrate existing `RoadworkSlug` rows in (`sluggable = Roadwork`,
   `parent_id null`, carry `is_current`).
3. Backfill area slugs during/after CBS import (slugify + collision rule + structural `parent_id`).
4. Repoint `ProjectController::showBySlug` / `redirectFromId` to the unified resolver;
   replace the `/{slug}` route with the `/{path}` listing route.
5. Drop `RoadworkSlug` once cut over (optional short-lived compat shim).

## URL generation (reverse) for the frontend

Wayfinder cannot type these (DB-driven). Provide:

- `$area->url()` / `ListingUrlMapper::build($query)` for links and pagination.
- `php artisan area:urls` to enumerate canonical area URLs (sitemap, smoke tests).

## Testing (PHPUnit feature)

- Resolve: `/amsterdam`; `/noord-holland/amsterdam` → 301 `/amsterdam`;
  ambiguous `/bergen` must extend to `/noord-holland/bergen` + `/limburg/bergen`;
  `centrum` (wijk) vs `centrum-buurt`.
- History: renamed gemeente old slug → 301 current; stale CBS vintage → 301.
- Facets: `/amsterdam/gestremd/wegwerkzaamheden/rijkswaterstaat` parse ↔ build round-trip.
- Roadwork detail still resolves; legacy `/projecten/{id}` redirect intact.
- Property: `build(parse(url)) === canonical(url)`.

## Decisions log

- Table scope: resolvable entities only (roadworks + areas); facets are handlers, not rows.
- Generalized slug history + 301 for all resolvable types.
- Storage: leaf slug only (no full-path string); hierarchy walked in code via `parent_id`.
- Collision winner: larger area (wijk) keeps bare slug; loser typed-suffix.
- Canonical: shortest-unique; longer/redundant/stale forms 301.
- Root-promotion capped at gemeente.
- Path facets: status, type, authority. Free-text `q`, `sort`, `page` stay query params.
```
