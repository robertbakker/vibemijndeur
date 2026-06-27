# JSON-LD Structured Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emit schema.org JSON-LD for the home, listing, and roadwork-detail pages so search/AI crawlers understand the content.

**Architecture:** A request-scoped `StructuredData` collector gathers schema.org node arrays; controllers push the nodes for their page; `app.blade.php` renders them as a single `<script type="application/ld+json">` with an `@graph`, server-side, at the end of `<body>`. Pure node-builder classes turn models/DTOs into arrays and are unit-tested in isolation. No SSR; no Inertia props are used to carry the markup.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia v3 (server-side blade shell only), Spatie LaravelData DTOs, PHPUnit 12, Pint.

## Global Constraints

- PHP files start with `declare(strict_types=1);`.
- Use constructor property promotion; explicit return types and param type hints everywhere.
- Prefer PHPDoc array-shape annotations over inline comments.
- JSON-LD renders in `<body>` (footer), never in `<head>`.
- Detail page type is `SpecialAnnouncement` (+ `Place`); **never** `Event`.
- Markup must only contain facts visible on the page (title, description, dates, location, geo). Breadcrumb markup mirrors the page's visible breadcrumb exactly.
- Site/brand name in markup is the literal string `voormijndeur` (config `app.name` is unset). Site URL via `url('/')`.
- Dates are ISO 8601 **date-only** (`Y-m-d`), matching the visible day. `addressCountry` is `NL`.
- All URLs absolute and same-domain (`url('/'.$slug)`, `url()->current()`).
- Run `vendor/bin/pint --dirty --format agent` before each commit.
- Use `/opt/homebrew/bin/php` for artisan/pint (shell aliases are broken; see project memory).

---

### Task 1: `StructuredData` collector

**Files:**
- Create: `app/StructuredData/StructuredData.php`
- Test: `tests/Unit/StructuredData/StructuredDataTest.php`

**Interfaces:**
- Produces: `App\StructuredData\StructuredData` with `push(array $node): void`, `nodes(): array`, `toScript(): string`. Empty collector → `toScript()` returns `''`. Non-empty → one `<script type="application/ld+json">` whose JSON is `{"@context":"https://schema.org","@graph":[...nodes]}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\StructuredData;

use App\StructuredData\StructuredData;
use Tests\TestCase;

class StructuredDataTest extends TestCase
{
    public function test_empty_collector_renders_nothing(): void
    {
        $this->assertSame('', (new StructuredData)->toScript());
    }

    public function test_pushed_nodes_render_as_one_graph_script(): void
    {
        $sd = new StructuredData;
        $sd->push(['@type' => 'WebSite', 'name' => 'voormijndeur']);
        $sd->push(['@type' => 'Organization', 'name' => 'voormijndeur']);

        $html = $sd->toScript();

        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $this->assertStringEndsWith('</script>', $html);

        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertCount(2, $decoded['@graph']);
        $this->assertSame('WebSite', $decoded['@graph'][0]['@type']);
    }

    public function test_script_closing_tags_are_escaped(): void
    {
        $sd = new StructuredData;
        $sd->push(['@type' => 'WebSite', 'name' => '</script><x>']);

        $this->assertStringNotContainsString('</script><x>', $sd->toScript());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Unit/StructuredData/StructuredDataTest.php`
Expected: FAIL — class `App\StructuredData\StructuredData` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

/**
 * Request-scoped collector of schema.org nodes, rendered as one JSON-LD
 * `@graph` script tag by the root blade view.
 */
class StructuredData
{
    /** @var list<array<string, mixed>> */
    private array $nodes = [];

    /**
     * @param  array<string, mixed>  $node
     */
    public function push(array $node): void
    {
        $this->nodes[] = $node;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function toScript(): string
    {
        if ($this->nodes === []) {
            return '';
        }

        $payload = ['@context' => 'https://schema.org', '@graph' => $this->nodes];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR,
        );

        return '<script type="application/ld+json">'.$json.'</script>';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Unit/StructuredData/StructuredDataTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/StructuredData/StructuredData.php tests/Unit/StructuredData/StructuredDataTest.php
git commit -m "feat: add StructuredData JSON-LD collector"
```

---

### Task 2: Organization + WebSite nodes

**Files:**
- Create: `app/StructuredData/OrganizationNode.php`
- Create: `app/StructuredData/WebSiteNode.php`
- Test: `tests/Feature/StructuredData/SiteNodesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\StructuredData\OrganizationNode::make(): array` → `['@type' => 'Organization', 'name' => 'voormijndeur', 'url' => url('/')]`.
  - `App\StructuredData\WebSiteNode::make(): array` → `['@type' => 'WebSite', 'name' => 'voormijndeur', 'url' => url('/')]`.
  - (These need the framework booted for `url()`, so the test extends `Tests\TestCase`.)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\StructuredData\OrganizationNode;
use App\StructuredData\WebSiteNode;
use Tests\TestCase;

class SiteNodesTest extends TestCase
{
    public function test_website_node_shape(): void
    {
        $node = WebSiteNode::make();

        $this->assertSame('WebSite', $node['@type']);
        $this->assertSame('voormijndeur', $node['name']);
        $this->assertSame(url('/'), $node['url']);
    }

    public function test_organization_node_shape(): void
    {
        $node = OrganizationNode::make();

        $this->assertSame('Organization', $node['@type']);
        $this->assertSame('voormijndeur', $node['name']);
        $this->assertSame(url('/'), $node['url']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/SiteNodesTest.php`
Expected: FAIL — node classes not found.

- [ ] **Step 3: Write minimal implementation**

`app/StructuredData/OrganizationNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

/**
 * The voormijndeur publisher organization. Embedded inline as `publisher`
 * on detail pages and as a standalone node on the homepage.
 */
class OrganizationNode
{
    /**
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        return [
            '@type' => 'Organization',
            'name' => 'voormijndeur',
            'url' => url('/'),
        ];
    }
}
```

`app/StructuredData/WebSiteNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

class WebSiteNode
{
    /**
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        return [
            '@type' => 'WebSite',
            'name' => 'voormijndeur',
            'url' => url('/'),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/SiteNodesTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/StructuredData/OrganizationNode.php app/StructuredData/WebSiteNode.php tests/Feature/StructuredData/SiteNodesTest.php
git commit -m "feat: add Organization and WebSite JSON-LD nodes"
```

---

### Task 3: Wire collector into blade + home page

**Files:**
- Modify: `app/Providers/AppServiceProvider.php` (register the scoped binding)
- Modify: `resources/views/app.blade.php:22-24` (render the script before `</body>`)
- Modify: `app/Http/Controllers/HomeController.php` (push WebSite + Organization)
- Test: `tests/Feature/StructuredData/HomeStructuredDataTest.php`

**Interfaces:**
- Consumes: `StructuredData`, `WebSiteNode::make()`, `OrganizationNode::make()`.
- Produces: the home response HTML contains exactly one `<script type="application/ld+json">` whose `@graph` holds a `WebSite` and an `Organization` node. This is the first end-to-end proof that the blade wiring works.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array<string, mixed>>
     */
    private function graphFrom(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_home_emits_website_and_organization(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $types = array_column($this->graphFrom($html), '@type');

        $this->assertContains('WebSite', $types);
        $this->assertContains('Organization', $types);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/HomeStructuredDataTest.php`
Expected: FAIL — no `ld+json` script in the HTML (preg_match returns 0).

- [ ] **Step 3a: Register the scoped binding**

In `app/Providers/AppServiceProvider.php`, inside `register()`, add:

```php
$this->app->scoped(\App\StructuredData\StructuredData::class);
```

- [ ] **Step 3b: Render in the blade shell**

In `resources/views/app.blade.php`, replace the body block:

```blade
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
```

with:

```blade
    <body class="font-sans antialiased">
        <x-inertia::app />
        {!! app(\App\StructuredData\StructuredData::class)->toScript() !!}
    </body>
```

- [ ] **Step 3c: Push nodes from HomeController**

In `app/Http/Controllers/HomeController.php`, add imports and use method injection:

```php
use App\StructuredData\OrganizationNode;
use App\StructuredData\StructuredData;
use App\StructuredData\WebSiteNode;
```

Change the signature and push before returning:

```php
    public function __invoke(StructuredData $structuredData): Response
    {
        // ... existing $roadworks query unchanged ...

        $structuredData->push(WebSiteNode::make());
        $structuredData->push(OrganizationNode::make());

        return Inertia::render('Home', [
            'projects' => RoadworkCard::collect($roadworks),
            'roadworksTotal' => DB::table('roadworks')->count(),
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/HomeStructuredDataTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Providers/AppServiceProvider.php resources/views/app.blade.php app/Http/Controllers/HomeController.php tests/Feature/StructuredData/HomeStructuredDataTest.php
git commit -m "feat: render JSON-LD in blade shell and on home page"
```

---

### Task 4: BreadcrumbList + Place + SpecialAnnouncement nodes

**Files:**
- Create: `app/StructuredData/BreadcrumbListNode.php`
- Create: `app/StructuredData/PlaceNode.php`
- Create: `app/StructuredData/SpecialAnnouncementNode.php`
- Test: `tests/Feature/StructuredData/DetailNodesTest.php`

**Interfaces:**
- Consumes: nothing (pure builders).
- Produces:
  - `BreadcrumbListNode::make(array $crumbs): array` — `$crumbs` is `list<array{name: string, url: ?string}>`. Returns `['@type' => 'BreadcrumbList', 'itemListElement' => [...]]` with 1-based `position`; a crumb whose `url` is `null` omits `item` (the current page).
  - `PlaceNode::make(string $name, ?float $lat, ?float $lng, ?string $locality, ?string $region): array` — `Place` with `PostalAddress` (`addressCountry: NL`, optional locality/region) and, when both coords present, numeric `GeoCoordinates`.
  - `SpecialAnnouncementNode::make(string $name, string $text, string $url, ?string $datePosted, ?string $expires, array $place, array $publisher): array` — `SpecialAnnouncement` with `spatialCoverage` = `$place`, `publisher` = `$publisher`, optional `datePosted`/`expires`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\StructuredData\BreadcrumbListNode;
use App\StructuredData\OrganizationNode;
use App\StructuredData\PlaceNode;
use App\StructuredData\SpecialAnnouncementNode;
use Tests\TestCase;

class DetailNodesTest extends TestCase
{
    public function test_breadcrumb_positions_and_current_item(): void
    {
        $node = BreadcrumbListNode::make([
            ['name' => 'Home', 'url' => 'https://x/'],
            ['name' => 'Werkzaamheden', 'url' => 'https://x/kaart'],
            ['name' => 'Gemeente Utrecht', 'url' => null],
        ]);

        $this->assertSame('BreadcrumbList', $node['@type']);
        $this->assertSame(1, $node['itemListElement'][0]['position']);
        $this->assertSame('https://x/', $node['itemListElement'][0]['item']);
        $this->assertSame(3, $node['itemListElement'][2]['position']);
        $this->assertArrayNotHasKey('item', $node['itemListElement'][2]);
    }

    public function test_place_node_with_and_without_geo(): void
    {
        $with = PlaceNode::make('Catharijnesingel', 52.0894, 5.1132, 'Utrecht', 'Utrecht');
        $this->assertSame('Place', $with['@type']);
        $this->assertSame('NL', $with['address']['addressCountry']);
        $this->assertSame('Utrecht', $with['address']['addressLocality']);
        $this->assertSame(52.0894, $with['geo']['latitude']);

        $without = PlaceNode::make('Onbekend', null, null, null, null);
        $this->assertArrayNotHasKey('geo', $without);
        $this->assertArrayNotHasKey('addressLocality', $without['address']);
    }

    public function test_special_announcement_shape(): void
    {
        $node = SpecialAnnouncementNode::make(
            'Werk A',
            'Kabels / Leidingen',
            'https://x/werk-a',
            '2026-07-01',
            '2026-09-01',
            PlaceNode::make('Plek', 52.0, 5.0, null, null),
            OrganizationNode::make(),
        );

        $this->assertSame('SpecialAnnouncement', $node['@type']);
        $this->assertSame('2026-07-01', $node['datePosted']);
        $this->assertSame('2026-09-01', $node['expires']);
        $this->assertSame('Place', $node['spatialCoverage']['@type']);
        $this->assertSame('Organization', $node['publisher']['@type']);
    }

    public function test_special_announcement_omits_null_dates(): void
    {
        $node = SpecialAnnouncementNode::make(
            'Werk A', 'tekst', 'https://x/werk-a', null, null,
            PlaceNode::make('Plek', null, null, null, null),
            OrganizationNode::make(),
        );

        $this->assertArrayNotHasKey('datePosted', $node);
        $this->assertArrayNotHasKey('expires', $node);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/DetailNodesTest.php`
Expected: FAIL — node classes not found.

- [ ] **Step 3: Write minimal implementations**

`app/StructuredData/BreadcrumbListNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

class BreadcrumbListNode
{
    /**
     * @param  list<array{name: string, url: ?string}>  $crumbs
     * @return array<string, mixed>
     */
    public static function make(array $crumbs): array
    {
        $items = [];

        foreach (array_values($crumbs) as $index => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $crumb['name'],
            ];

            if (($crumb['url'] ?? null) !== null) {
                $item['item'] = $crumb['url'];
            }

            $items[] = $item;
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
```

`app/StructuredData/PlaceNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

class PlaceNode
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        string $name,
        ?float $latitude,
        ?float $longitude,
        ?string $locality,
        ?string $region,
    ): array {
        $address = ['@type' => 'PostalAddress', 'addressCountry' => 'NL'];

        if ($locality !== null) {
            $address['addressLocality'] = $locality;
        }

        if ($region !== null) {
            $address['addressRegion'] = $region;
        }

        $place = ['@type' => 'Place', 'name' => $name, 'address' => $address];

        if ($latitude !== null && $longitude !== null) {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $place;
    }
}
```

`app/StructuredData/SpecialAnnouncementNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

class SpecialAnnouncementNode
{
    /**
     * @param  array<string, mixed>  $place
     * @param  array<string, mixed>  $publisher
     * @return array<string, mixed>
     */
    public static function make(
        string $name,
        string $text,
        string $url,
        ?string $datePosted,
        ?string $expires,
        array $place,
        array $publisher,
    ): array {
        $node = [
            '@type' => 'SpecialAnnouncement',
            'name' => $name,
            'text' => $text,
            'url' => $url,
            'spatialCoverage' => $place,
            'publisher' => $publisher,
        ];

        if ($datePosted !== null) {
            $node['datePosted'] = $datePosted;
        }

        if ($expires !== null) {
            $node['expires'] = $expires;
        }

        return $node;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/DetailNodesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/StructuredData/BreadcrumbListNode.php app/StructuredData/PlaceNode.php app/StructuredData/SpecialAnnouncementNode.php tests/Feature/StructuredData/DetailNodesTest.php
git commit -m "feat: add Breadcrumb, Place and SpecialAnnouncement JSON-LD nodes"
```

---

### Task 5: Emit detail-page structured data from ListingController

**Files:**
- Modify: `app/Http/Controllers/ListingController.php`
- Test: `tests/Feature/StructuredData/DetailStructuredDataTest.php`

**Interfaces:**
- Consumes: `StructuredData`, `SpecialAnnouncementNode`, `PlaceNode`, `BreadcrumbListNode`, `OrganizationNode`, and `Roadwork` relations `gemeenten` / `provincies`.
- Produces: a roadwork detail response whose `@graph` contains a `SpecialAnnouncement` (with nested `Place` + `publisher`) and a `BreadcrumbList` mirroring the visible trail (Home → `/`, Werkzaamheden → `/kaart`, `locationLabel` current).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetailStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    private function upsert(): Roadwork
    {
        $line = ['type' => 'LineString', 'coordinates' => [[4.89, 52.37], [4.90, 52.37]]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $line, 'properties' => ['causeDescription' => 'GAS Hoofdstraat']], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', 'NDW_SD_1',
            ['kind' => 'WORK', 'severity' => 'high', 'status' => 'running', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true, 'start_date' => '2026-07-01T00:00:00Z', 'end_date' => '2026-09-01T00:00:00Z'],
            $line, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        return Roadwork::where('source_id', 'NDW_SD_1')->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graphFrom(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_detail_emits_special_announcement_and_breadcrumb(): void
    {
        $this->upsert();

        $graph = $this->graphFrom(
            $this->get('/s-gravenhage-gas-hoofdstraat')->assertOk()->getContent()
        );

        $byType = [];
        foreach ($graph as $node) {
            $byType[$node['@type']] = $node;
        }

        $this->assertArrayHasKey('SpecialAnnouncement', $byType);
        $this->assertArrayHasKey('BreadcrumbList', $byType);

        $announcement = $byType['SpecialAnnouncement'];
        $this->assertSame('GAS Hoofdstraat', $announcement['name']);
        $this->assertSame('2026-07-01', $announcement['datePosted']);
        $this->assertSame('2026-09-01', $announcement['expires']);
        $this->assertSame('Place', $announcement['spatialCoverage']['@type']);
        $this->assertSame('NL', $announcement['spatialCoverage']['address']['addressCountry']);
        $this->assertArrayHasKey('geo', $announcement['spatialCoverage']);
        $this->assertSame('voormijndeur', $announcement['publisher']['name']);

        $crumbs = $byType['BreadcrumbList']['itemListElement'];
        $this->assertSame('Home', $crumbs[0]['name']);
        $this->assertSame(url('/'), $crumbs[0]['item']);
        $this->assertSame('Werkzaamheden', $crumbs[1]['name']);
        $this->assertSame(url('/kaart'), $crumbs[1]['item']);
        $this->assertSame("Gemeente 's-Gravenhage", $crumbs[2]['name']);
        $this->assertArrayNotHasKey('item', $crumbs[2]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/DetailStructuredDataTest.php`
Expected: FAIL — no `ld+json` script on the detail page.

- [ ] **Step 3: Push nodes from the detail branch**

In `app/Http/Controllers/ListingController.php`, add imports:

```php
use App\StructuredData\BreadcrumbListNode;
use App\StructuredData\OrganizationNode;
use App\StructuredData\PlaceNode;
use App\StructuredData\SpecialAnnouncementNode;
use App\StructuredData\StructuredData;
use Carbon\CarbonImmutable;
```

Add `StructuredData` to the constructor:

```php
    public function __construct(
        private readonly ListingUrlMapper $mapper,
        private readonly RoadworkSegment $roadworkSegment,
        private readonly WerkzaamhedenController $werkzaamheden,
        private readonly StructuredData $structuredData,
    ) {}
```

In the detail branch, eager-load the areas, build the DTO once, push the nodes, then render:

```php
            $roadwork = Roadwork::query()
                ->withRepresentativePoint()
                ->with(['currentSlug', 'gemeenten', 'provincies'])
                ->findOrFail($resolution->roadworkId);

            $project = ProjectDetail::fromModel($roadwork);

            $this->structuredData->push(SpecialAnnouncementNode::make(
                $project->title,
                $project->description,
                url('/'.$project->slug),
                $this->isoDate($roadwork->start_date),
                $this->isoDate($roadwork->end_date),
                PlaceNode::make(
                    $project->locationLabel,
                    $project->latitude,
                    $project->longitude,
                    $roadwork->gemeenten->first()?->name,
                    $roadwork->provincies->first()?->name,
                ),
                OrganizationNode::make(),
            ));

            $this->structuredData->push(BreadcrumbListNode::make([
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Werkzaamheden', 'url' => url('/kaart')],
                ['name' => $project->locationLabel, 'url' => null],
            ]));

            return Inertia::render('Projecten/Show', [
                'project' => $project,
            ]);
```

Add the private helper at the end of the class:

```php
    private function isoDate(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->format('Y-m-d');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/DetailStructuredDataTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ListingController.php tests/Feature/StructuredData/DetailStructuredDataTest.php
git commit -m "feat: emit SpecialAnnouncement JSON-LD on roadwork detail page"
```

---

### Task 6: ItemList + CollectionPage nodes

**Files:**
- Create: `app/StructuredData/ItemListNode.php`
- Create: `app/StructuredData/CollectionPageNode.php`
- Test: `tests/Feature/StructuredData/ListingNodesTest.php`

**Interfaces:**
- Consumes: `App\Data\RoadworkCard` (public `->slug`, `->title`).
- Produces:
  - `ItemListNode::fromCards(array $cards): array` — `$cards` is `list<RoadworkCard>`. Returns `['@type' => 'ItemList', 'numberOfItems' => N, 'itemListElement' => [...]]` with URL-only `ListItem`s (`@type`, `position`, `url` = `url('/'.$slug)`, `name` = title). Cards with a `null` slug are skipped; positions stay contiguous.
  - `CollectionPageNode::make(string $name, string $url, array $itemList): array` — `['@type' => 'CollectionPage', 'name', 'url', 'mainEntity' => $itemList]`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\Data\RoadworkCard;
use App\StructuredData\CollectionPageNode;
use App\StructuredData\ItemListNode;
use Tests\TestCase;

class ListingNodesTest extends TestCase
{
    private function card(?string $slug, string $title): RoadworkCard
    {
        return new RoadworkCard(
            id: 1,
            slug: $slug,
            title: $title,
            locationLabel: 'X',
            period: '',
            typeLabel: '',
            icon: '',
            statusKey: 'active',
            statusLabel: '',
            markerColor: '',
            chipBg: '',
            chipText: '',
        );
    }

    public function test_item_list_skips_slugless_cards_and_numbers_contiguously(): void
    {
        $node = ItemListNode::fromCards([
            $this->card('werk-a', 'Werk A'),
            $this->card(null, 'No slug'),
            $this->card('werk-b', 'Werk B'),
        ]);

        $this->assertSame('ItemList', $node['@type']);
        $this->assertSame(2, $node['numberOfItems']);
        $this->assertSame(1, $node['itemListElement'][0]['position']);
        $this->assertSame(url('/werk-a'), $node['itemListElement'][0]['url']);
        $this->assertSame('Werk A', $node['itemListElement'][0]['name']);
        $this->assertSame(2, $node['itemListElement'][1]['position']);
        $this->assertSame('Werk B', $node['itemListElement'][1]['name']);
    }

    public function test_collection_page_wraps_item_list(): void
    {
        $node = CollectionPageNode::make('Werkzaamheden', 'https://x/werkzaamheden', ['@type' => 'ItemList']);

        $this->assertSame('CollectionPage', $node['@type']);
        $this->assertSame('Werkzaamheden', $node['name']);
        $this->assertSame('https://x/werkzaamheden', $node['url']);
        $this->assertSame('ItemList', $node['mainEntity']['@type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/ListingNodesTest.php`
Expected: FAIL — node classes not found.

- [ ] **Step 3: Write minimal implementations**

`app/StructuredData/ItemListNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

use App\Data\RoadworkCard;

class ItemListNode
{
    /**
     * @param  list<RoadworkCard>  $cards
     * @return array<string, mixed>
     */
    public static function fromCards(array $cards): array
    {
        $elements = [];
        $position = 1;

        foreach ($cards as $card) {
            if ($card->slug === null) {
                continue;
            }

            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'url' => url('/'.$card->slug),
                'name' => $card->title,
            ];
        }

        return [
            '@type' => 'ItemList',
            'numberOfItems' => count($elements),
            'itemListElement' => $elements,
        ];
    }
}
```

`app/StructuredData/CollectionPageNode.php`:

```php
<?php

declare(strict_types=1);

namespace App\StructuredData;

class CollectionPageNode
{
    /**
     * @param  array<string, mixed>  $itemList
     * @return array<string, mixed>
     */
    public static function make(string $name, string $url, array $itemList): array
    {
        return [
            '@type' => 'CollectionPage',
            'name' => $name,
            'url' => $url,
            'mainEntity' => $itemList,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/ListingNodesTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/StructuredData/ItemListNode.php app/StructuredData/CollectionPageNode.php tests/Feature/StructuredData/ListingNodesTest.php
git commit -m "feat: add ItemList and CollectionPage JSON-LD nodes"
```

---

### Task 7: Emit listing structured data from WerkzaamhedenController

**Files:**
- Modify: `app/Http/Controllers/WerkzaamhedenController.php`
- Test: `tests/Feature/StructuredData/ListingStructuredDataTest.php`

**Interfaces:**
- Consumes: `StructuredData`, `CollectionPageNode`, `ItemListNode`, `BreadcrumbListNode`, and the `$cards` already built in `render()`.
- Produces: every listing response (both `/werkzaamheden` and the pretty-URL entry via `renderFromQuery`) emits a `CollectionPage` (with an `ItemList` of the page's visible cards) and a `BreadcrumbList` (Home → `/`, Werkzaamheden current). Pushing happens in the shared `render()`, so both entry points are covered.

> Note: `render()` calls `RoadworkSearch::browse`, which hits Meilisearch. The test asserts the CollectionPage + BreadcrumbList scaffolding, which is emitted regardless of how many hits return (an empty index yields `numberOfItems: 0`). If Meilisearch is not running locally, start it and reindex per the project's meilisearch workflow before running this test.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array<string, mixed>>
     */
    private function graphFrom(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_listing_emits_collection_page_and_breadcrumb(): void
    {
        $graph = $this->graphFrom(
            $this->get('/werkzaamheden')->assertOk()->getContent()
        );

        $byType = [];
        foreach ($graph as $node) {
            $byType[$node['@type']] = $node;
        }

        $this->assertArrayHasKey('CollectionPage', $byType);
        $this->assertArrayHasKey('BreadcrumbList', $byType);

        $this->assertSame('Werkzaamheden in de buurt', $byType['CollectionPage']['name']);
        $this->assertSame('ItemList', $byType['CollectionPage']['mainEntity']['@type']);

        $crumbs = $byType['BreadcrumbList']['itemListElement'];
        $this->assertSame('Home', $crumbs[0]['name']);
        $this->assertSame(url('/'), $crumbs[0]['item']);
        $this->assertSame('Werkzaamheden', $crumbs[1]['name']);
        $this->assertArrayNotHasKey('item', $crumbs[1]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/ListingStructuredDataTest.php`
Expected: FAIL — no `ld+json` script on the listing page.

- [ ] **Step 3: Push nodes from render()**

In `app/Http/Controllers/WerkzaamhedenController.php`, add imports:

```php
use App\StructuredData\BreadcrumbListNode;
use App\StructuredData\CollectionPageNode;
use App\StructuredData\ItemListNode;
use App\StructuredData\StructuredData;
```

Add `StructuredData` to the constructor:

```php
    public function __construct(
        private readonly RoadworkSearch $search,
        private readonly StructuredData $structuredData,
    ) {}
```

In `render()`, after `$cards` is built and before `return Inertia::render(...)`, push the nodes:

```php
        $this->structuredData->push(CollectionPageNode::make(
            'Werkzaamheden in de buurt',
            url()->current(),
            ItemListNode::fromCards($cards),
        ));

        $this->structuredData->push(BreadcrumbListNode::make([
            ['name' => 'Home', 'url' => url('/')],
            ['name' => 'Werkzaamheden', 'url' => null],
        ]));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData/ListingStructuredDataTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/WerkzaamhedenController.php tests/Feature/StructuredData/ListingStructuredDataTest.php
git commit -m "feat: emit CollectionPage JSON-LD on werkzaamheden listing"
```

---

### Task 8: Full-suite check + manual validation

**Files:** none (verification only).

- [ ] **Step 1: Run the structured-data feature + unit tests together**

Run: `/opt/homebrew/bin/php artisan test --compact tests/Feature/StructuredData tests/Unit/StructuredData`
Expected: PASS (all tasks' tests green).

- [ ] **Step 2: Run the full suite (ask the user first per project convention)**

Run: `/opt/homebrew/bin/php artisan test --compact`
Expected: PASS (no regressions from the controller/blade changes).

- [ ] **Step 3: Manual rich-result validation (one-time, non-blocking)**

Copy the JSON-LD emitted on a home, a listing, and a detail page (View Source → the `application/ld+json` block) into:
- Google Rich Results Test: https://search.google.com/test/rich-results
- schema.org validator: https://validator.schema.org/

Confirm: BreadcrumbList is eligible; no errors on SpecialAnnouncement / CollectionPage / ItemList (warnings about "no rich result" for those types are expected and fine). Fix any reported syntax errors before considering the work done.

---

## Self-Review

**Spec coverage:**
- JSON-LD format, body placement, no SSR → Task 1 (collector) + Task 3 (blade). ✓
- Home WebSite + Organization → Tasks 2, 3. ✓
- Listing CollectionPage + ItemList (URL-only, visible items) + Breadcrumb → Tasks 6, 7. ✓
- Detail SpecialAnnouncement + Place + geo + publisher + Breadcrumb, `Event` rejected → Tasks 4, 5. ✓
- Publisher honesty (voormijndeur as publisher) → `OrganizationNode`, used as `publisher`. ✓
- Geo/address (NL, locality=gemeente, region=provincie, numeric coords) → `PlaceNode`, Task 5 wiring. ✓
- ISO date-only matching visible → `isoDate()` helper, Task 5. ✓
- Match-visible + breadcrumb mirrors visible trail → breadcrumb crumbs match the DOM in `Show.vue` / `Werkzaamheden.vue`. ✓
- Testing: unit per builder + feature per page → Tasks 1–7; manual validation → Task 8. ✓
- Out of scope: Kaart, SSR, visible breadcrumb UI, OG/Twitter tags → not in any task. ✓

**Placeholder scan:** none — every step has full code/commands.

**Type consistency:** `push`/`nodes`/`toScript` (Task 1) used in Tasks 3,5,7. `make()`/`fromCards()` signatures defined in Tasks 2,4,6 match their call sites in Tasks 3,5,7. `RoadworkCard` props (`slug`, `title`) match the DTO. `isoDate()` defined and used in Task 5. ✓
