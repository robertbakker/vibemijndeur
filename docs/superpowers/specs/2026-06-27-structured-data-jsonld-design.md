# Structured Data (JSON-LD) for voormijndeur

**Date:** 2026-06-27
**Status:** Approved design

## Goal

Add schema.org structured data (JSON-LD) to the public pages so search engines and
AI/LLM crawlers can understand the content. Listing and detail pages are the priority;
the home page gets sitewide publisher identity.

There is **no roadwork-specific rich result** in Google. The realistic wins are:
breadcrumb rich results, and improved semantic/AI understanding of the pages. We optimise
for honest, accurate markup that matches visible content — not for chasing a snippet that
does not exist.

## Key decisions

- **Format: JSON-LD.** Google's stated preference over Microdata/RDFa; decoupled from
  visible HTML, easiest to template and keep in sync.
- **Placement: end of `<body>` (footer), server-rendered into `app.blade.php`.**
  JSON-LD is not executed as JavaScript, is not render-blocking, and is ~1–2 KB, so it has
  **no Core Web Vitals cost**. Google explicitly allows it in head or body. Body placement
  keeps `<head>` uncluttered.
- **No SSR required.** The app currently has no SSR and manages head client-side via
  Inertia `<Head>`. We do **not** add SSR for this work — plain server-side blade injection
  is crawler-safe without JS and trivially stays in sync with the source data. (Full Inertia
  SSR remains a possible separate future project, out of scope here.)
- **Detail page type: `SpecialAnnouncement` + `Place`.** A roadwork is a dated disruption
  notice with a location and a responsible authority. `SpecialAnnouncement` is the honest
  schema.org fit (schema.org lists "local government" and "public transport closures").
  - **`Event` is rejected.** Although schema.org-valid, Google's Event rich-result policy
    warns that marking up non-events (not publicly attendable/bookable) "may take manual
    action and disqualify your entire website from rich results." A roadwork is not attended
    or booked. No rich result is gained either way, so the penalty risk is not justified.
  - The `SpecialAnnouncement` rich result was retired by Google on 2025-07-31, so this is
    semantic / AI-understanding value, not a snippet.
- **Publisher honesty.** voormijndeur.nl aggregates official feeds, so **voormijndeur is the
  page publisher**. The responsible `road_authority` / gemeente is named honestly as the
  source (in `text` / `spatialCoverage`), never falsely claimed as the page publisher.

## Architecture

### `App\Support\StructuredData` registry
- Request-scoped singleton (bound in the container; Laravel rebinds per request).
- Controllers push schema.org node arrays into it.
- Emits a **single** `<script type="application/ld+json">` containing a `@graph` array that
  combines all pushed nodes (breadcrumb + announcement + place + organization) in one tag —
  the recommended multi-node pattern.
- `toScript(): string` returns the full `<script>...</script>` (HTML-safe, JSON encoded with
  `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`). Empty registry → returns empty string.

### Blade
- `app.blade.php` renders `{!! app(\App\Support\StructuredData::class)->toScript() !!}`
  immediately before `</body>`. Blade renders after the controller has populated the
  registry, so ordering is correct. No Inertia props are polluted and nothing is sent over
  the wire on partial reloads.

### Node builder classes — `App\StructuredData\*`
One class per schema.org node type. Each takes models/DTOs and returns a plain array. Each is
unit-testable in isolation.

- `WebSiteNode` — `WebSite` (name, url).
- `OrganizationNode` — `Organization` for voormijndeur.nl (the site publisher).
- `BreadcrumbListNode` — builds a `BreadcrumbList` from an ordered list of (name, url) crumbs;
  the final/current crumb omits `item`.
- `ItemListNode` — `ItemList` of URL-only `ListItem`s (`@type`, `position`, `url`, `name`).
- `CollectionPageNode` — `CollectionPage` wrapping the `ItemList` as `mainEntity`.
- `PlaceNode` — `Place` with `PostalAddress` + `GeoCoordinates`.
- `SpecialAnnouncementNode` — `SpecialAnnouncement` referencing a `PlaceNode` via
  `spatialCoverage`, plus `publisher` (voormijndeur Organization).

## Per-page composition

### Home `/` (HomeController)
- `WebSiteNode` + `OrganizationNode` — sitewide identity, voormijndeur.nl as publisher.

### Listing `/werkzaamheden` (WerkzaamhedenController)
- `CollectionPageNode` whose `mainEntity` is an `ItemListNode` built from **only the
  currently-visible `results`** (respects pagination/filters → matches visible content).
  Each `ListItem`: `position`, `url` (absolute detail URL), `name` (card title).
- `BreadcrumbListNode`.

### Detail `/{slug}` (ProjectController::showBySlug)
- `SpecialAnnouncementNode`:
  - `name` (title), `text` (description), `url` (absolute), `datePosted`, `expires`
    (= roadwork `end_date`).
  - `spatialCoverage` → `PlaceNode`.
  - `publisher` → voormijndeur `Organization`. Responsible `road_authority`/gemeente named in
    `text` / `spatialCoverage` as source.
- `PlaceNode`: `name` (locationLabel) + `PostalAddress` (`addressCountry: "NL"`,
  `addressLocality` = gemeente, `addressRegion` = provincie, `streetAddress` if available) +
  `GeoCoordinates` (numeric `latitude`/`longitude`).
- `BreadcrumbListNode` (Home › … › roadwork title).

## Data sourcing & honesty rules

- Builders read from the **`Roadwork` model** (not only the display DTO) so dates are real
  **ISO 8601 with NL timezone offset** (`+02:00` CEST / `+01:00` CET), derived from
  `start_date` / `end_date`. Date-only acceptable where no time is shown.
- **Only emit fields actually visible on the page** (title, description, dates, location,
  authority, geo — all present on the detail page). This satisfies Google's "markup must
  match visible content" policy, the #1 manual-action trigger.
- Absolute URLs via `route()` / `url()`. All listed URLs are same-domain.
- Numeric (not string) latitude/longitude.

## Breadcrumb visibility

Google generates breadcrumb rich results from markup, and policy prefers the markup to match
a visible breadcrumb trail. The pages do not currently render a visible breadcrumb. For this
pass we **emit breadcrumb markup anyway** (common, low risk). Adding a visible breadcrumb UI
is deferred to a possible follow-up.

## Testing

- **Feature tests** per page (Home, Listing, Detail): assert the response HTML contains the
  `<script type="application/ld+json">`, that it is valid JSON, that the expected `@type`s are
  present in the `@graph`, and that values match the underlying model data (dates, name, geo,
  breadcrumb depth, item count/URLs on the listing).
- **Unit tests** per node builder: given a model/DTO, assert the produced array shape and
  values (including ISO date formatting and numeric coords).
- One-time **manual validation** of representative output against Google's Rich Results Test
  and the schema.org validator.

## Out of scope

- Kaart `/kaart` (data loads client-side via the `/api/roadworks` API; structured data is
  awkward and low-value there).
- Full Inertia SSR.
- Visible breadcrumb UI component.
- Open Graph / Twitter Card meta tags (separate SEO concern).
