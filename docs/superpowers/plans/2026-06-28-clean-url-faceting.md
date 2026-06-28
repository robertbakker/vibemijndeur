# Clean-URL Faceting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Werkzaamheden facet sidebar navigate to clean, shareable pretty URLs (e.g. `/amsterdam,utrecht/gepland/wegdek`) instead of `/werkzaamheden?gemeente[]=…`, with every facet dimension supporting comma-joined multi-value OR-lists.

**Architecture:** Extend the existing `App\Router` pipeline (`ListingUrlMapper` + `UrlSegment` handlers + `ListingQuery`) so each path segment is a comma-joined OR-list of one dimension. Area slugs become globally unique so any area resolves from a single segment; hierarchical nesting (`/noord-holland/amsterdam`) is still accepted on input but 301s to the shortest single-segment canonical. A new `App\Router\FacetUrlBuilder` turns the current query + Meilisearch facet distributions into `App\Data\FacetOption` DTOs, each carrying the clean URL you land on if you toggle that option. The Vue page just navigates to `option.url`.

**Tech Stack:** Laravel 13, PHP 8.5, Inertia v3 + Vue 3, Meilisearch via Laravel Scout, spatie/laravel-data + spatie/laravel-typescript-transformer, PHPUnit 12, Pint.

## Global Constraints

- PHP: always curly braces; constructor property promotion; explicit return types + param type hints; PHPDoc array shapes over inline comments.
- Run `vendor/bin/pint --dirty --format agent` after touching PHP, before committing.
- Tests are PHPUnit (not Pest). Create with `php artisan make:test --phpunit {name}`. Run with `php artisan test --compact --filter={name}`.
- Comma values in every canonical URL segment are sorted ascending by emitted slug string (deterministic dedupe → stable 301s).
- Area slugs are globally unique after this work. Canonical area form is always a single path segment.
- Facet dimensions and their Meilisearch attributes: `status`→`status_key`, `type`→`work_type`, `gemeente`→`gemeente`, `provincie`→`provincie`, `authority`→`road_authority`.
- Filter semantics: `(area OR area …) AND (status OR …) AND (type OR …) AND (authority OR …)`. Area values are OR'd together even across levels.
- No querystring facets. Only `q`, `sort`, `page` remain as query string, layered on the current clean path.
- Regenerate the TS types after changing any `App\Data` class: `php artisan typescript:transform`.

---

## File Structure

**Backend — modify**
- `app/Router/ListingQuery.php` — single `area` → `areas` list; add/remove helpers; split `toFilters()` (AND dims) from new `toAreaFilters()` (area OR group).
- `app/Router/Segments/StatusSegment.php` — comma multi-value match/build.
- `app/Router/Segments/TypeSegment.php` — comma multi-value match/build.
- `app/Router/Segments/AuthoritySegment.php` — comma multi-value match/build.
- `app/Router/Segments/AreaSegment.php` — comma multi-area + nesting narrowing on input; sorted single-segment build.
- `app/Router/CanonicalPath.php` — simplify to the area's own unique slug.
- `app/Router/AreaSlugGenerator.php` — globally-unique slugs; retire stale slugs (`is_current=false`) instead of deleting.
- `app/Roadworks/RoadworkSearch.php` — `browse()` accepts an area OR-group filter.
- `app/Http/Controllers/WerkzaamhedenController.php` — pass area OR-group filter; delegate facet building to `FacetUrlBuilder`.

**Backend — create**
- `app/Data/FacetOption.php` — typed facet option DTO (carries `url`).
- `app/Data/FacetGroup.php` — typed facet group DTO (key, title, options).
- `app/Router/FacetUrlBuilder.php` — builds `FacetGroup[]` with per-option toggle URLs.

**Frontend — modify**
- `resources/js/pages/Werkzaamheden.vue` — use generated `App.Data.FacetOption`/`FacetGroup`; navigate to `option.url`; `q`/`sort`/`page` as query on current path.

**Tests — create/modify**
- `tests/Unit/Router/ListingQueryTest.php` (create)
- `tests/Feature/Router/FacetSegmentsTest.php` (modify — add comma cases)
- `tests/Feature/Router/AreaSegmentTest.php` (modify — multi-area)
- `tests/Feature/Router/AreaSlugGeneratorTest.php` (modify — uniqueness + retire)
- `tests/Feature/Router/CanonicalPathTest.php` (modify)
- `tests/Feature/Router/ListingUrlMapperTest.php` (modify — `area()`→`areas()`, multi-value round trips)
- `tests/Feature/Router/FacetUrlBuilderTest.php` (create)
- `tests/Unit/Roadworks/RoadworkSearchFilterTest.php` (create — filter expression building)

---

## Task 1: ListingQuery multi-area + filter split

**Files:**
- Modify: `app/Router/ListingQuery.php`
- Test: `tests/Unit/Router/ListingQueryTest.php` (create)

**Interfaces:**
- Produces:
  - `addArea(string $level, int $id, string $name): void`
  - `removeAreaByName(string $name): void`
  - `areas(): array` — `list<array{level:string,id:int,name:string}>`
  - `addStatus`, `addType`, `addAuthority` (unchanged), plus `removeStatus(string $key): void`, `removeType(string $label): void`, `removeAuthority(string $name): void`
  - `hasStatus(string $key): bool`, `hasType(string $label): bool`, `hasAuthority(string $name): bool`, `hasAreaName(string $name): bool`
  - `toFilters(): array<string,list<string>>` — only `status_key`/`work_type`/`road_authority` now (NO area)
  - `toAreaFilters(): array<string,list<string>>` — area names grouped by filterable attribute (`gemeente`,`provincie`)
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Router/ListingQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Router;

use App\Router\ListingQuery;
use PHPUnit\Framework\TestCase;

class ListingQueryTest extends TestCase
{
    public function test_it_collects_multiple_areas(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addArea('gemeente', 2, 'Utrecht');

        $this->assertCount(2, $query->areas());
        $this->assertTrue($query->hasAreaName('Utrecht'));
    }

    public function test_remove_area_by_name(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addArea('provincie', 9, 'Utrecht');
        $query->removeAreaByName('Amsterdam');

        $this->assertSame([['level' => 'provincie', 'id' => 9, 'name' => 'Utrecht']], $query->areas());
    }

    public function test_to_filters_excludes_area_and_keeps_dimensions(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addStatus('planned');
        $query->addType('Wegdek');
        $query->addAuthority('Rijkswaterstaat');

        $this->assertSame([
            'status_key' => ['planned'],
            'work_type' => ['Wegdek'],
            'road_authority' => ['Rijkswaterstaat'],
        ], $query->toFilters());
    }

    public function test_to_area_filters_groups_by_attribute(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addArea('gemeente', 2, 'Utrecht');
        $query->addArea('provincie', 9, 'Noord-Holland');

        $this->assertSame([
            'gemeente' => ['Amsterdam', 'Utrecht'],
            'provincie' => ['Noord-Holland'],
        ], $query->toAreaFilters());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=ListingQueryTest`
Expected: FAIL (`addArea`/`areas`/`toAreaFilters` not defined).

- [ ] **Step 3: Rewrite ListingQuery**

Replace the area-related members in `app/Router/ListingQuery.php`. Change the single `?array $area` property and its methods to a list, and split the filter methods:

```php
    /** @var list<array{level:string,id:int,name:string}> */
    private array $areas = [];
```

Remove the old `private ?array $area = null;`, `setArea()`, and `area()`. Add:

```php
    public function addArea(string $level, int $id, string $name): void
    {
        foreach ($this->areas as $existing) {
            if ($existing['id'] === $id && $existing['level'] === $level) {
                return;
            }
        }
        $this->areas[] = ['level' => $level, 'id' => $id, 'name' => $name];
    }

    public function removeAreaByName(string $name): void
    {
        $this->areas = array_values(array_filter(
            $this->areas,
            fn (array $a): bool => $a['name'] !== $name,
        ));
    }

    /** @return list<array{level:string,id:int,name:string}> */
    public function areas(): array
    {
        return $this->areas;
    }

    public function hasAreaName(string $name): bool
    {
        foreach ($this->areas as $a) {
            if ($a['name'] === $name) {
                return true;
            }
        }

        return false;
    }
```

Add removal + presence helpers for the flat dimensions (place beside the existing `addStatus`/`addType`/`addAuthority`):

```php
    public function removeStatus(string $key): void
    {
        $this->statuses = array_values(array_filter($this->statuses, fn (string $s): bool => $s !== $key));
    }

    public function removeType(string $label): void
    {
        $this->types = array_values(array_filter($this->types, fn (string $t): bool => $t !== $label));
    }

    public function removeAuthority(string $name): void
    {
        $this->authorities = array_values(array_filter($this->authorities, fn (string $a): bool => $a !== $name));
    }

    public function hasStatus(string $key): bool
    {
        return in_array($key, $this->statuses, true);
    }

    public function hasType(string $label): bool
    {
        return in_array($label, $this->types, true);
    }

    public function hasAuthority(string $name): bool
    {
        return in_array($name, $this->authorities, true);
    }
```

Replace `toFilters()` (drop the area branch) and add `toAreaFilters()`:

```php
    /**
     * Non-area filters keyed by Meilisearch attribute (AND-combined upstream).
     *
     * @return array<string, list<string>>
     */
    public function toFilters(): array
    {
        $filters = [];

        if ($this->statuses !== []) {
            $filters['status_key'] = $this->statuses;
        }
        if ($this->types !== []) {
            $filters['work_type'] = $this->types;
        }
        if ($this->authorities !== []) {
            $filters['road_authority'] = $this->authorities;
        }

        return $filters;
    }

    /**
     * Area names grouped by their filterable Meilisearch attribute. Only
     * gemeente/provincie are indexed as facets; other levels are dropped.
     *
     * @return array<string, list<string>>
     */
    public function toAreaFilters(): array
    {
        $attribute = ['gemeente' => 'gemeente', 'provincie' => 'provincie'];

        $grouped = [];
        foreach ($this->areas as $area) {
            $key = $attribute[$area['level']] ?? null;
            if ($key === null) {
                continue;
            }
            $grouped[$key][] = $area['name'];
        }

        return $grouped;
    }
```

Update `cacheKey()` to serialize `$this->areas` instead of `$this->area`:

```php
    public function cacheKey(): string
    {
        return md5(serialize([
            $this->areas, $this->statuses, $this->types,
            $this->authorities, $this->term, $this->sort, $this->page,
        ]));
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=ListingQueryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/ListingQuery.php tests/Unit/Router/ListingQueryTest.php
git commit -m "feat(router): ListingQuery holds multiple areas, splits area filters"
```

---

## Task 2: StatusSegment comma multi-value

**Files:**
- Modify: `app/Router/Segments/StatusSegment.php`
- Test: `tests/Feature/Router/FacetSegmentsTest.php` (add a method)

**Interfaces:**
- Consumes: `ListingQuery::addStatus`, `ListingQuery::statuses` (from Task 1).
- Produces: `StatusSegment::match` splits the peeked segment on `,`; `build` emits all statuses, sorted, comma-joined.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Router/FacetSegmentsTest.php`:

```php
    public function test_status_segment_parses_and_builds_comma_list(): void
    {
        $query = new \App\Router\ListingQuery;
        $cursor = new \App\Router\SegmentCursor(['afgerond,gepland']);
        $segment = new \App\Router\Segments\StatusSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['done', 'planned'], $query->statuses());
        // build is sorted by slug: afgerond < gepland
        $this->assertSame('afgerond,gepland', $segment->build($query));
    }

    public function test_status_segment_rejects_segment_with_unknown_value(): void
    {
        $query = new \App\Router\ListingQuery;
        $cursor = new \App\Router\SegmentCursor(['gepland,zwembad']);
        $segment = new \App\Router\Segments\StatusSegment;

        $this->assertSame(0, $segment->match($cursor, $query));
        $this->assertSame([], $query->statuses());
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=FacetSegmentsTest`
Expected: FAIL (build returns only the first status; mixed segment partially matches).

- [ ] **Step 3: Rewrite StatusSegment**

Replace the body of `app/Router/Segments/StatusSegment.php` `match`/`build`:

```php
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segment = $cursor->peek(1)[0] ?? null;
        if ($segment === null) {
            return 0;
        }

        $values = explode(',', $segment);
        $statuses = [];
        foreach ($values as $value) {
            $status = RoadworkStatus::fromSlug($value);
            if ($status === null) {
                return 0; // whole segment belongs to another handler
            }
            $statuses[] = $status;
        }

        foreach ($statuses as $status) {
            $query->addStatus($status->value);
        }
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $slugs = array_map(
            fn (string $value): string => RoadworkStatus::from($value)->slug(),
            $query->statuses(),
        );
        if ($slugs === []) {
            return null;
        }
        sort($slugs);

        return implode(',', $slugs);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=FacetSegmentsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/Segments/StatusSegment.php tests/Feature/Router/FacetSegmentsTest.php
git commit -m "feat(router): status segment supports comma OR-lists"
```

---

## Task 3: TypeSegment comma multi-value

**Files:**
- Modify: `app/Router/Segments/TypeSegment.php`
- Test: `tests/Feature/Router/FacetSegmentsTest.php` (add a method)

**Interfaces:**
- Consumes: `ListingQuery::addType`, `ListingQuery::types`.
- Produces: `TypeSegment::match` splits on `,`, resolves each value via `RoadworkType::labels()` slug match; `build` emits `Str::slug` of each type, sorted, comma-joined.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Router/FacetSegmentsTest.php`:

```php
    public function test_type_segment_parses_and_builds_comma_list(): void
    {
        $query = new \App\Router\ListingQuery;
        $cursor = new \App\Router\SegmentCursor(['wegdek,riool']);
        $segment = new \App\Router\Segments\TypeSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['Wegdek', 'Riool'], $query->types());
        // sorted by slug: riool < wegdek
        $this->assertSame('riool,wegdek', $segment->build($query));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=FacetSegmentsTest`
Expected: FAIL.

- [ ] **Step 3: Rewrite TypeSegment**

Replace `match`/`build` in `app/Router/Segments/TypeSegment.php`:

```php
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segment = $cursor->peek(1)[0] ?? null;
        if ($segment === null) {
            return 0;
        }

        $labels = RoadworkType::labels();
        $resolved = [];
        foreach (explode(',', $segment) as $value) {
            $label = null;
            foreach ($labels as $candidate) {
                if (Str::slug($candidate) === $value) {
                    $label = $candidate;
                    break;
                }
            }
            if ($label === null) {
                return 0;
            }
            $resolved[] = $label;
        }

        foreach ($resolved as $label) {
            $query->addType($label);
        }
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $slugs = array_map(fn (string $label): string => Str::slug($label), $query->types());
        if ($slugs === []) {
            return null;
        }
        sort($slugs);

        return implode(',', $slugs);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=FacetSegmentsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/Segments/TypeSegment.php tests/Feature/Router/FacetSegmentsTest.php
git commit -m "feat(router): type segment supports comma OR-lists"
```

---

## Task 4: AuthoritySegment comma multi-value

**Files:**
- Modify: `app/Router/Segments/AuthoritySegment.php`
- Test: `tests/Feature/Router/FacetSegmentsTest.php` (add a method)

**Interfaces:**
- Consumes: `ListingQuery::addAuthority`, `ListingQuery::authorities`; `AuthoritySegment::slugToName` cache (existing).
- Produces: `AuthoritySegment::match` splits on `,`; `build` emits `Str::slug` of each authority, sorted, comma-joined.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Router/FacetSegmentsTest.php`. This test seeds two roadworks so the authority cache resolves; adjust the factory call to match existing usage in that file if needed:

```php
    public function test_authority_segment_parses_and_builds_comma_list(): void
    {
        \App\Models\Roadwork::factory()->create(['road_authority' => 'Gemeente Amsterdam']);
        \App\Models\Roadwork::factory()->create(['road_authority' => 'Rijkswaterstaat']);
        \Illuminate\Support\Facades\Cache::forget('router:authorities');

        $query = new \App\Router\ListingQuery;
        $cursor = new \App\Router\SegmentCursor(['rijkswaterstaat,gemeente-amsterdam']);
        $segment = app(\App\Router\Segments\AuthoritySegment::class);

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['Rijkswaterstaat', 'Gemeente Amsterdam'], $query->authorities());
        $this->assertSame('gemeente-amsterdam,rijkswaterstaat', $segment->build($query));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=FacetSegmentsTest`
Expected: FAIL.

- [ ] **Step 3: Rewrite AuthoritySegment match/build**

Replace `match`/`build` in `app/Router/Segments/AuthoritySegment.php` (keep `slugToName()` unchanged):

```php
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segment = $cursor->peek(1)[0] ?? null;
        if ($segment === null) {
            return 0;
        }

        $map = $this->slugToName();
        $resolved = [];
        foreach (explode(',', $segment) as $value) {
            $name = $map[$value] ?? null;
            if ($name === null) {
                return 0;
            }
            $resolved[] = $name;
        }

        foreach ($resolved as $name) {
            $query->addAuthority($name);
        }
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $slugs = array_map(fn (string $name): string => Str::slug($name), $query->authorities());
        if ($slugs === []) {
            return null;
        }
        sort($slugs);

        return implode(',', $slugs);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=FacetSegmentsTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/Segments/AuthoritySegment.php tests/Feature/Router/FacetSegmentsTest.php
git commit -m "feat(router): authority segment supports comma OR-lists"
```

---

## Task 5: AreaSlugGenerator global uniqueness + retire history

**Files:**
- Modify: `app/Router/AreaSlugGenerator.php`
- Test: `tests/Feature/Router/AreaSlugGeneratorTest.php` (add methods)

**Interfaces:**
- Produces: after `rebuild()`, every `is_current=true` area slug is globally unique; previous current slugs for a changed area are kept with `is_current=false` (redirect history).

**Design notes:** Collision token order — when a base slug is already taken globally by another current row, append the area's parent area name (province for a gemeente, gemeente for wijk/buurt); if still taken, append the numeric area id. `rebuild()` must no longer hard-delete existing area slugs: instead mark all current area slugs `is_current=false` first, regenerate current rows, then drop any now-duplicate retired rows that exactly equal a freshly-written current `(slug,sluggable_type,sluggable_id)`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Router/AreaSlugGeneratorTest.php`:

```php
    public function test_same_named_gemeenten_get_globally_unique_slugs(): void
    {
        $nh = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $li = Provincie::factory()->create(['name' => 'Limburg']);
        Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $nh->id]);
        Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $li->id]);

        app(AreaSlugGenerator::class)->rebuild();

        $slugs = Slug::query()
            ->where('sluggable_type', (new Gemeente)->getMorphClass())
            ->where('is_current', true)
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['bergen-limburg', 'bergen-noord-holland'], $slugs);
    }

    public function test_rebuild_retires_changed_slug_as_redirect_history(): void
    {
        $nh = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $bergen = Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $nh->id]);

        app(AreaSlugGenerator::class)->rebuild();
        $this->assertDatabaseHas('slugs', ['slug' => 'bergen', 'is_current' => true]);

        // A second same-named gemeente appears; rebuild must change Bergen's slug.
        $li = Provincie::factory()->create(['name' => 'Limburg']);
        Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $li->id]);
        app(AreaSlugGenerator::class)->rebuild();

        // Old bare slug retained as non-current redirect for the NH Bergen.
        $this->assertDatabaseHas('slugs', [
            'slug' => 'bergen',
            'sluggable_type' => $bergen->getMorphClass(),
            'sluggable_id' => $bergen->id,
            'is_current' => false,
        ]);
        $this->assertDatabaseHas('slugs', [
            'slug' => 'bergen-noord-holland',
            'sluggable_id' => $bergen->id,
            'is_current' => true,
        ]);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=AreaSlugGeneratorTest`
Expected: FAIL (current generator deletes history and only de-dupes per parent).

- [ ] **Step 3: Rewrite AreaSlugGenerator**

In `app/Router/AreaSlugGenerator.php`, change `rebuild()` to retire instead of delete, and add a final cleanup of retired rows that now equal a current row. Replace the body of `rebuild()`:

```php
    public function rebuild(): void
    {
        DB::transaction(function (): void {
            $areaTypes = array_map(fn (string $c): string => (new $c)->getMorphClass(), [
                Landsdeel::class, Provincie::class, Gemeente::class, Wijk::class, Buurt::class,
            ]);

            // Retire (don't delete) so stale slugs keep redirecting.
            Slug::query()->whereIn('sluggable_type', $areaTypes)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $this->generate(Landsdeel::class, null, fn (Landsdeel $a): ?int => null);
            $this->generate(Provincie::class, Landsdeel::class, fn (Provincie $a): ?int => $a->landsdeel_id);
            $this->generate(Gemeente::class, Provincie::class, fn (Gemeente $a): ?int => $a->provincie_id);
            $this->generate(Wijk::class, Gemeente::class, fn (Wijk $a): ?int => $a->gemeente_id);
            $this->generate(Buurt::class, Gemeente::class, fn (Buurt $a): ?int => $a->gemeente_id);

            $this->pruneRedundantRetired($areaTypes);
        });
    }

    /**
     * Drop retired rows whose (slug,type,id) exactly equals a freshly written
     * current row — they would be duplicate dead weight, not a real redirect.
     *
     * @param  list<string>  $areaTypes
     */
    private function pruneRedundantRetired(array $areaTypes): void
    {
        $current = Slug::query()
            ->whereIn('sluggable_type', $areaTypes)
            ->where('is_current', true)
            ->get(['slug', 'sluggable_type', 'sluggable_id']);

        foreach ($current as $row) {
            Slug::query()
                ->where('is_current', false)
                ->where('slug', $row->slug)
                ->where('sluggable_type', $row->sluggable_type)
                ->where('sluggable_id', $row->sluggable_id)
                ->delete();
        }
    }
```

Replace `uniqueSlug()` so collision detection is global (not per parent) and the token chain is parent-name then id:

```php
    /**
     * Globally-unique slug. Base from the name; on a collision with any current
     * row, qualify with the parent area name, then the numeric id.
     *
     * @param  class-string<Model>  $model
     */
    private function uniqueSlug(Model $area, ?int $parentId, string $model): string
    {
        $base = Str::slug((string) $area->name);
        if (! $this->slugTaken($base)) {
            return $base;
        }

        $parentName = $parentId === null
            ? null
            : Slug::query()->whereKey($parentId)->value('slug');

        if ($parentName !== null) {
            $qualified = "{$base}-{$parentName}";
            if (! $this->slugTaken($qualified)) {
                return $qualified;
            }
        }

        return "{$base}-{$area->getKey()}";
    }

    private function slugTaken(string $slug): bool
    {
        return Slug::query()
            ->where('slug', $slug)
            ->where('is_current', true)
            ->exists();
    }
```

Remove the now-unused `LEVEL_TOKEN` constant.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=AreaSlugGeneratorTest`
Expected: PASS. The existing `test_wijk_wins_bare_slug_buurt_gets_suffix` will now expect `centrum-centrum` (buurt's parent gemeente/wijk slug). Update that test's final assertion to `$this->assertStringStartsWith('centrum-', $buurtSlug->slug);` and `$this->assertNotSame('centrum', $buurtSlug->slug);`.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/AreaSlugGenerator.php tests/Feature/Router/AreaSlugGeneratorTest.php
git commit -m "feat(router): globally-unique area slugs, retire stale slugs as redirects"
```

---

## Task 6: CanonicalPath simplification

**Files:**
- Modify: `app/Router/CanonicalPath.php`
- Test: `tests/Feature/Router/CanonicalPathTest.php`

**Interfaces:**
- Produces: `CanonicalPath::for(Slug $slug): string` returns `$slug->slug` (single segment — uniqueness is now guaranteed by the generator).

- [ ] **Step 1: Update the test**

Open `tests/Feature/Router/CanonicalPathTest.php`. Replace any assertion expecting a nested path (e.g. `noord-holland/bergen`) with the single-segment slug. Add:

```php
    public function test_for_returns_the_slug_itself(): void
    {
        $province = \App\Models\Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = \App\Models\Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $province->id]);
        $parent = \App\Models\Slug::factory()->create([
            'slug' => 'noord-holland', 'parent_id' => null,
            'sluggable_type' => $province->getMorphClass(), 'sluggable_id' => $province->id,
        ]);
        $slug = \App\Models\Slug::factory()->create([
            'slug' => 'bergen-noord-holland', 'parent_id' => $parent->id,
            'sluggable_type' => $gemeente->getMorphClass(), 'sluggable_id' => $gemeente->id,
        ]);

        $this->assertSame('bergen-noord-holland', \App\Router\CanonicalPath::for($slug));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=CanonicalPathTest`
Expected: FAIL if any old nesting test remains; the new test may already pass once old ones are removed. Remove obsolete nesting-promotion tests in this file.

- [ ] **Step 3: Simplify CanonicalPath**

Replace the whole class body of `app/Router/CanonicalPath.php`:

```php
<?php

declare(strict_types=1);

namespace App\Router;

use App\Models\Slug;

final class CanonicalPath
{
    /**
     * The canonical single-segment path for an area slug. Area slugs are
     * globally unique (see AreaSlugGenerator), so the slug itself is canonical.
     */
    public static function for(Slug $slug): string
    {
        return $slug->slug;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=CanonicalPathTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/CanonicalPath.php tests/Feature/Router/CanonicalPathTest.php
git commit -m "refactor(router): CanonicalPath returns the unique slug directly"
```

---

## Task 7: AreaSegment multi-area + nesting input + sorted build

**Files:**
- Modify: `app/Router/Segments/AreaSegment.php`
- Test: `tests/Feature/Router/AreaSegmentTest.php` (add methods)

**Interfaces:**
- Consumes: `ListingQuery::addArea`, `ListingQuery::areas` (Task 1); `CanonicalPath::for` (Task 6); `Slug` model.
- Produces:
  - `match()` greedily consumes consecutive area segments. Segment 0 comma values resolve to current root-type slugs (OR-list, union). Each later segment, if all its comma values resolve as current slugs whose `parent_id` is in the previous segment's resolved set, replaces the resolved set (drill-down). Stops at the first non-area segment. All finally-resolved slugs are added via `addArea`.
  - `build()` emits one sorted comma-joined segment of the areas' unique slugs.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Router/AreaSegmentTest.php` (match the file's existing seeding helpers; this assumes factories as in `ListingUrlMapperTest`):

```php
    public function test_it_resolves_comma_or_list_of_gemeenten(): void
    {
        $nh = \App\Models\Provincie::factory()->create(['name' => 'Noord-Holland']);
        $ut = \App\Models\Provincie::factory()->create(['name' => 'Utrecht']);
        $ams = \App\Models\Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $nh->id]);
        $utr = \App\Models\Gemeente::factory()->create(['name' => 'Utrecht', 'provincie_id' => $ut->id]);
        \App\Models\Slug::factory()->create(['slug' => 'amsterdam', 'parent_id' => null, 'sluggable_type' => $ams->getMorphClass(), 'sluggable_id' => $ams->id]);
        \App\Models\Slug::factory()->create(['slug' => 'utrecht', 'parent_id' => null, 'sluggable_type' => $utr->getMorphClass(), 'sluggable_id' => $utr->id]);

        $query = new \App\Router\ListingQuery;
        $cursor = new \App\Router\SegmentCursor(['amsterdam,utrecht']);
        $consumed = app(\App\Router\Segments\AreaSegment::class)->match($cursor, $query);

        $this->assertSame(1, $consumed);
        $this->assertCount(2, $query->areas());
        $this->assertSame('amsterdam,utrecht', app(\App\Router\Segments\AreaSegment::class)->build($query));
    }

    public function test_nesting_narrows_to_child_then_builds_single_segment(): void
    {
        $nh = \App\Models\Provincie::factory()->create(['name' => 'Noord-Holland']);
        $ams = \App\Models\Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $nh->id]);
        $prov = \App\Models\Slug::factory()->create(['slug' => 'noord-holland', 'parent_id' => null, 'sluggable_type' => $nh->getMorphClass(), 'sluggable_id' => $nh->id]);
        \App\Models\Slug::factory()->create(['slug' => 'amsterdam', 'parent_id' => $prov->id, 'sluggable_type' => $ams->getMorphClass(), 'sluggable_id' => $ams->id]);

        $query = new \App\Router\ListingQuery;
        $cursor = new \App\Router\SegmentCursor(['noord-holland', 'amsterdam']);
        $consumed = app(\App\Router\Segments\AreaSegment::class)->match($cursor, $query);

        $this->assertSame(2, $consumed);
        // Drill-down narrows to the child gemeente only.
        $this->assertSame([['level' => 'gemeente', 'id' => $ams->id, 'name' => 'Amsterdam']], $query->areas());
        $this->assertSame('amsterdam', app(\App\Router\Segments\AreaSegment::class)->build($query));
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=AreaSegmentTest`
Expected: FAIL.

- [ ] **Step 3: Rewrite AreaSegment match/build**

Replace `match()` and `build()` in `app/Router/Segments/AreaSegment.php` (keep `levelFor`/`morphForLevel`):

```php
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segments = $cursor->remaining();
        if ($segments === []) {
            return 0;
        }

        $rootMorphs = array_map(fn (string $c): string => (new $c)->getMorphClass(), self::ROOT_TYPES);

        // Segment 0: comma OR-list of current root-type slugs.
        $resolved = $this->resolveRootSegment($segments[0], $rootMorphs);
        if ($resolved->isEmpty()) {
            return 0;
        }

        $consumed = 1;

        // Following segments: drill into children while every comma value
        // resolves as a child of the current resolved set.
        for ($i = 1; $i < count($segments); $i++) {
            $children = $this->resolveChildSegment($segments[$i], $resolved->pluck('id')->all());
            if ($children === null) {
                break; // belongs to a status/type/authority handler
            }
            $resolved = $children;
            $consumed++;
        }

        foreach ($resolved as $slug) {
            $area = $slug->sluggable;
            $query->addArea($this->levelFor($slug), (int) $area->getKey(), (string) $area->name);
        }
        $cursor->consume($consumed);

        return $consumed;
    }

    /**
     * @param  list<string>  $rootMorphs
     * @return \Illuminate\Support\Collection<int, Slug>
     */
    private function resolveRootSegment(string $segment, array $rootMorphs): \Illuminate\Support\Collection
    {
        $values = explode(',', $segment);
        $slugs = collect();
        foreach ($values as $value) {
            $slug = Slug::query()
                ->where('slug', $value)
                ->where('is_current', true)
                ->whereIn('sluggable_type', $rootMorphs)
                ->first();
            if ($slug === null) {
                return collect(); // any unresolved value disqualifies the segment as area
            }
            $slugs->push($slug);
        }

        return $slugs;
    }

    /**
     * @param  list<int>  $parentIds
     * @return \Illuminate\Support\Collection<int, Slug>|null  null when the segment is not a child-area segment
     */
    private function resolveChildSegment(string $segment, array $parentIds): ?\Illuminate\Support\Collection
    {
        $values = explode(',', $segment);
        $slugs = collect();
        foreach ($values as $value) {
            $slug = Slug::query()
                ->where('slug', $value)
                ->where('is_current', true)
                ->whereIn('parent_id', $parentIds)
                ->first();
            if ($slug === null) {
                return null;
            }
            $slugs->push($slug);
        }

        return $slugs;
    }

    public function build(ListingQuery $query): ?string
    {
        $areas = $query->areas();
        if ($areas === []) {
            return null;
        }

        $slugs = [];
        foreach ($areas as $area) {
            $slug = Slug::query()
                ->where('sluggable_type', $this->morphForLevel($area['level']))
                ->where('sluggable_id', $area['id'])
                ->where('is_current', true)
                ->firstOrFail();
            $slugs[] = CanonicalPath::for($slug);
        }
        sort($slugs);

        return implode(',', $slugs);
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=AreaSegmentTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/Segments/AreaSegment.php tests/Feature/Router/AreaSegmentTest.php
git commit -m "feat(router): area segment supports comma OR-lists and nesting input"
```

---

## Task 8: ListingUrlMapper round-trip + ListingController guard update

**Files:**
- Modify: `app/Http/Controllers/ListingController.php:44` (the area guard)
- Test: `tests/Feature/Router/ListingUrlMapperTest.php`, `tests/Feature/Router/PrettyUrlRoutingTest.php`

**Interfaces:**
- Consumes: `ListingQuery::areas()` (replaces removed `area()`).
- Produces: full parse→build round trip for multi-value paths; `/utrecht,amsterdam` 301s to `/amsterdam,utrecht`.

- [ ] **Step 1: Update mapper tests to multi-area API**

In `tests/Feature/Router/ListingUrlMapperTest.php`, replace `$query->area()['level']` with an `areas()` assertion and add a multi-value + sorting round trip:

```php
    public function test_it_parses_area_and_facets(): void
    {
        $this->seedAmsterdam();
        $query = $this->mapper()->parse('amsterdam/gepland/wegdek');

        $this->assertSame('gemeente', $query->areas()[0]['level']);
        $this->assertSame(['planned'], $query->statuses());
        $this->assertSame(['Wegdek'], $query->types());
    }

    public function test_comma_area_list_is_sorted_in_canonical(): void
    {
        $this->seedAmsterdam();
        $province = \App\Models\Provincie::factory()->create(['name' => 'Utrecht']);
        $utr = \App\Models\Gemeente::factory()->create(['name' => 'Utrecht', 'provincie_id' => $province->id]);
        \App\Models\Slug::factory()->create(['slug' => 'utrecht', 'parent_id' => null, 'sluggable_type' => $utr->getMorphClass(), 'sluggable_id' => $utr->id]);

        $query = $this->mapper()->parse('utrecht,amsterdam');
        $this->assertSame('/amsterdam,utrecht', $this->mapper()->build($query));
    }
```

Note: `seedAmsterdam()` creates `amsterdam` under the `noord-holland` parent slug. Since global uniqueness is not enforced by the manual seed, keep its `amsterdam` slug parentless-resolvable by ensuring no other `amsterdam` current slug exists in these tests (they don't). The `test_round_trip_build_equals_canonical` case (`/noord-holland/amsterdam` → `/amsterdam`) still holds via Task 7 nesting.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=ListingUrlMapperTest`
Expected: FAIL (`area()` removed; controller still references it).

- [ ] **Step 3: Update ListingController guard**

In `app/Http/Controllers/ListingController.php`, change the listing-detection condition at line ~44 from `$query->area() !== null` to `$query->areas() !== []`:

```php
            if ($query->areas() !== [] || $query->statuses() !== [] || $query->types() !== [] || $query->authorities() !== []) {
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=ListingUrlMapperTest && php artisan test --compact --filter=PrettyUrlRoutingTest`
Expected: PASS. If `PrettyUrlRoutingTest` asserts old nesting canonicals, update them to single-segment.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ListingController.php tests/Feature/Router/ListingUrlMapperTest.php tests/Feature/Router/PrettyUrlRoutingTest.php
git commit -m "feat(router): multi-area round trips; controller guards on areas()"
```

---

## Task 9: RoadworkSearch area OR-group filter

**Files:**
- Modify: `app/Roadworks/RoadworkSearch.php`
- Test: `tests/Unit/Roadworks/RoadworkSearchFilterTest.php` (create)

**Interfaces:**
- Produces: `RoadworkSearch::browse(string $query, array $filters = [], array $sort = [], int $offset = 0, int $limit = 24, array $facets = [], array $areaFilters = []): array`. The new `$areaFilters` (`array<string,list<string>>` keyed by attribute) is combined into a single OR group: a nested array appended to the Meilisearch filter list. A protected `buildFilter(array $filters, array $areaFilters): array` builds the expression so it can be unit-tested without Meilisearch.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Roadworks/RoadworkSearchFilterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Roadworks;

use App\Roadworks\RoadworkSearch;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RoadworkSearchFilterTest extends TestCase
{
    /** @param array<string,mixed> $filters */
    private function build(array $filters, array $areaFilters): array
    {
        $method = new ReflectionMethod(RoadworkSearch::class, 'buildFilter');

        return $method->invoke(new RoadworkSearch, $filters, $areaFilters);
    }

    public function test_dimension_filters_are_anded(): void
    {
        $filter = $this->build(['status_key' => ['planned']], []);

        $this->assertSame(['status_key IN ["planned"]'], $filter);
    }

    public function test_area_filters_become_a_single_or_group(): void
    {
        $filter = $this->build(
            ['status_key' => ['planned']],
            ['gemeente' => ['Amsterdam', 'Utrecht'], 'provincie' => ['Noord-Holland']],
        );

        $this->assertSame([
            'status_key IN ["planned"]',
            ['gemeente IN ["Amsterdam", "Utrecht"]', 'provincie IN ["Noord-Holland"]'],
        ], $filter);
    }

    public function test_single_area_attribute_is_not_wrapped(): void
    {
        $filter = $this->build([], ['gemeente' => ['Amsterdam']]);

        $this->assertSame(['gemeente IN ["Amsterdam"]'], $filter);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=RoadworkSearchFilterTest`
Expected: FAIL (`buildFilter` does not exist).

- [ ] **Step 3: Add buildFilter and extend browse**

In `app/Roadworks/RoadworkSearch.php`, add a `buildFilter` method (next to `scalarFilters`):

```php
    /**
     * Combine AND'd dimension filters with a single OR'd area group.
     *
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @param  array<string, list<string>>  $areaFilters
     * @return list<string|list<string>>
     */
    protected function buildFilter(array $filters, array $areaFilters): array
    {
        $filter = $this->scalarFilters($filters);

        $areaGroup = $this->scalarFilters($areaFilters);
        if (count($areaGroup) === 1) {
            $filter[] = $areaGroup[0];
        } elseif (count($areaGroup) > 1) {
            $filter[] = $areaGroup; // nested array = OR in Meilisearch
        }

        return $filter;
    }
```

Change `browse()`'s signature and its filter line:

```php
    public function browse(string $query, array $filters = [], array $sort = [], int $offset = 0, int $limit = 24, array $facets = [], array $areaFilters = []): array
    {
        $filter = $this->buildFilter($filters, $areaFilters);

        return Roadwork::search($query, function (Indexes $index, string $query, array $options) use ($filter, $sort, $offset, $limit, $facets) {
            if ($filter !== []) {
                $options['filter'] = $filter;
            }
            // ... rest unchanged ...
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=RoadworkSearchFilterTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Roadworks/RoadworkSearch.php tests/Unit/Roadworks/RoadworkSearchFilterTest.php
git commit -m "feat(search): browse() supports an area OR-group filter"
```

---

## Task 10: FacetOption + FacetGroup DTOs

**Files:**
- Create: `app/Data/FacetOption.php`, `app/Data/FacetGroup.php`
- Test: `tests/Unit/Data/FacetOptionTest.php` (create)

**Interfaces:**
- Produces:
  - `App\Data\FacetOption` — readonly: `string $key, string $label, int $count, bool $checked, string $url, ?string $dot = null`.
  - `App\Data\FacetGroup` — readonly: `string $key, string $title, list<FacetOption> $options`.
  - Generated TS: `App.Data.FacetOption`, `App.Data.FacetGroup`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Data/FacetOptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\FacetOption;
use PHPUnit\Framework\TestCase;

class FacetOptionTest extends TestCase
{
    public function test_it_exposes_the_toggle_url(): void
    {
        $option = new FacetOption('planned', 'Gepland', 12, false, '/gepland', '#2F6BD8');

        $this->assertSame('/gepland', $option->url);
        $this->assertFalse($option->checked);
        $this->assertSame('#2F6BD8', $option->dot);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=FacetOptionTest`
Expected: FAIL (class missing).

- [ ] **Step 3: Create the DTOs**

`app/Data/FacetOption.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One facet sidebar option: its label, document count, whether it's currently
 * selected, and the clean URL to navigate to when toggled.
 */
class FacetOption extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public bool $checked,
        public string $url,
        public ?string $dot = null,
    ) {}
}
```

`app/Data/FacetGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * A facet sidebar group (e.g. "Gemeente") with its options.
 */
class FacetGroup extends Data
{
    /**
     * @param  list<FacetOption>  $options
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $options,
    ) {}
}
```

- [ ] **Step 4: Run + regenerate types**

Run: `php artisan test --compact --filter=FacetOptionTest`
Expected: PASS.

Run: `php artisan typescript:transform`
Expected: `resources/js/types/generated.d.ts` (or the project's TS types file) now declares `App.Data.FacetOption` and `App.Data.FacetGroup`.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data/FacetOption.php app/Data/FacetGroup.php tests/Unit/Data/FacetOptionTest.php resources/js/types
git commit -m "feat(data): FacetOption + FacetGroup DTOs with toggle url"
```

---

## Task 11: FacetUrlBuilder

**Files:**
- Create: `app/Router/FacetUrlBuilder.php`
- Test: `tests/Feature/Router/FacetUrlBuilderTest.php` (create)

**Interfaces:**
- Consumes: `ListingUrlMapper::build`, `ListingQuery` (Task 1 helpers), `App\Data\FacetOption`, `App\Data\RoadworkStatus`, `App\Models\Gemeente`/`Provincie`.
- Produces: `FacetUrlBuilder::options(ListingQuery $current, string $dimension, array $rawOptions): list<FacetOption>` where `$dimension ∈ {status,type,gemeente,provincie,authority}` and `$rawOptions` is `list<array{key:string,label:string,count:int,checked:bool,dot?:string}>`. Each returned `FacetOption` carries the clean URL produced by cloning `$current`, toggling that one value in the correct dimension, and calling `mapper->build`.

**Design note:** Area dimensions (`gemeente`,`provincie`) toggle by resolving the option `key` (the area name) to its area row(s) via the matching model. Toggling off removes every area whose name matches. Cloning: build a fresh `ListingQuery` from `$current`'s `areas()`/`statuses()`/`types()`/`authorities()` so the original is untouched.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Router/FacetUrlBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Router\FacetUrlBuilder;
use App\Router\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacetUrlBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function seedAmsterdam(): Gemeente
    {
        $province = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $province->id]);
        Slug::factory()->create([
            'slug' => 'amsterdam', 'parent_id' => null,
            'sluggable_type' => $gemeente->getMorphClass(), 'sluggable_id' => $gemeente->id,
        ]);

        return $gemeente;
    }

    public function test_unselected_status_option_url_adds_the_status(): void
    {
        $this->seedAmsterdam();
        $current = new ListingQuery;
        $current->addArea('gemeente', (int) Gemeente::first()->id, 'Amsterdam');

        $options = app(FacetUrlBuilder::class)->options($current, 'status', [
            ['key' => 'planned', 'label' => 'Gepland', 'count' => 3, 'checked' => false, 'dot' => '#2F6BD8'],
        ]);

        $this->assertSame('/amsterdam/gepland', $options[0]->url);
        $this->assertFalse($options[0]->checked);
    }

    public function test_selected_gemeente_option_url_removes_it(): void
    {
        $gemeente = $this->seedAmsterdam();
        $current = new ListingQuery;
        $current->addArea('gemeente', (int) $gemeente->id, 'Amsterdam');
        $current->addStatus('planned');

        $options = app(FacetUrlBuilder::class)->options($current, 'gemeente', [
            ['key' => 'Amsterdam', 'label' => 'Amsterdam', 'count' => 9, 'checked' => true],
        ]);

        // Toggling Amsterdam off leaves only the status segment.
        $this->assertSame('/gepland', $options[0]->url);
        $this->assertTrue($options[0]->checked);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=FacetUrlBuilderTest`
Expected: FAIL (class missing).

- [ ] **Step 3: Create FacetUrlBuilder**

`app/Router/FacetUrlBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Router;

use App\Data\FacetOption;
use App\Data\RoadworkStatus;
use App\Models\Gemeente;
use App\Models\Provincie;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns Meilisearch facet rows into {@see FacetOption} DTOs, each carrying the
 * clean URL you land on after toggling that one value against the current query.
 */
final class FacetUrlBuilder
{
    /** @var array<string, class-string<Model>> area dimension => model */
    private const AREA_MODELS = [
        'gemeente' => Gemeente::class,
        'provincie' => Provincie::class,
    ];

    public function __construct(private readonly ListingUrlMapper $mapper) {}

    /**
     * @param  list<array{key:string,label:string,count:int,checked:bool,dot?:string}>  $rawOptions
     * @return list<FacetOption>
     */
    public function options(ListingQuery $current, string $dimension, array $rawOptions): array
    {
        $out = [];
        foreach ($rawOptions as $raw) {
            $toggled = $this->toggle($current, $dimension, $raw['key'], $raw['checked']);
            $out[] = new FacetOption(
                key: $raw['key'],
                label: $raw['label'],
                count: $raw['count'],
                checked: $raw['checked'],
                url: $this->mapper->build($toggled),
                dot: $raw['dot'] ?? null,
            );
        }

        return $out;
    }

    private function toggle(ListingQuery $current, string $dimension, string $key, bool $checked): ListingQuery
    {
        $next = $this->clone($current);

        match ($dimension) {
            'status' => $checked ? $next->removeStatus($key) : $next->addStatus($key),
            'type' => $checked ? $next->removeType($key) : $next->addType($key),
            'authority' => $checked ? $next->removeAuthority($key) : $next->addAuthority($key),
            'gemeente', 'provincie' => $this->toggleArea($next, $dimension, $key, $checked),
            default => null,
        };

        return $next;
    }

    private function toggleArea(ListingQuery $query, string $dimension, string $name, bool $checked): void
    {
        if ($checked) {
            $query->removeAreaByName($name);

            return;
        }

        /** @var class-string<Model> $model */
        $model = self::AREA_MODELS[$dimension];
        foreach ($model::query()->where('name', $name)->get() as $area) {
            $query->addArea($dimension, (int) $area->getKey(), (string) $area->name);
        }
    }

    private function clone(ListingQuery $query): ListingQuery
    {
        $copy = new ListingQuery;
        foreach ($query->areas() as $area) {
            $copy->addArea($area['level'], $area['id'], $area['name']);
        }
        foreach ($query->statuses() as $status) {
            $copy->addStatus($status);
        }
        foreach ($query->types() as $type) {
            $copy->addType($type);
        }
        foreach ($query->authorities() as $authority) {
            $copy->addAuthority($authority);
        }

        return $copy;
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=FacetUrlBuilderTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Router/FacetUrlBuilder.php tests/Feature/Router/FacetUrlBuilderTest.php
git commit -m "feat(router): FacetUrlBuilder emits clean toggle URLs per facet option"
```

---

## Task 12: WerkzaamhedenController delegates facet building + area OR filter

**Files:**
- Modify: `app/Http/Controllers/WerkzaamhedenController.php`
- Test: `tests/Feature/WerkzaamhedenPageTest.php` (create or extend the existing page test if present)

**Interfaces:**
- Consumes: `FacetUrlBuilder::options` (Task 11), `RoadworkSearch::browse` area filter (Task 9), `ListingQuery::toAreaFilters`/`toFilters`.
- Produces: the Inertia `facets` prop is now `array<string, FacetGroup>` (each group's options are `FacetOption` with `url`). The `__invoke` query-string entry builds an empty-then-filtered `ListingQuery` so both entry points share one render path.

**Design note:** `render()` currently takes raw `$filters` keyed by attribute. Refactor so both entry points construct a `ListingQuery` and pass it to `render(ListingQuery $query, Request-derived q/sort/page)`. The disjunctive facet distributions still drop each group's own selection; that logic stays, but each group's resulting rows are passed through `FacetUrlBuilder` against the full current `ListingQuery`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/WerkzaamhedenPageTest.php` (this asserts the prop shape; it does not need a live Meili index if the test environment already stubs search — match the existing pattern used by other feature tests that hit `/werkzaamheden`; if those use a real Meili, gate with the same setup):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\FacetOption;
use App\Router\FacetUrlBuilder;
use App\Router\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WerkzaamhedenPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_facet_url_builder_is_wired_for_status(): void
    {
        // Unit-level guard that the controller's chosen collaborator produces URLs.
        $current = new ListingQuery;
        $options = app(FacetUrlBuilder::class)->options($current, 'status', [
            ['key' => 'planned', 'label' => 'Gepland', 'count' => 1, 'checked' => false, 'dot' => '#2F6BD8'],
        ]);

        $this->assertInstanceOf(FacetOption::class, $options[0]);
        $this->assertSame('/gepland', $options[0]->url);
    }
}
```

> If the project already has a feature test that renders `/werkzaamheden` with a seeded/stubbed Meili index, extend that instead and assert via Inertia `->has('facets.status.0.url')`.

- [ ] **Step 2: Run to verify it fails (or passes trivially) then write controller change**

Run: `php artisan test --compact --filter=WerkzaamhedenPageTest`
Expected: PASS for the guard test (it exercises Task 11). Proceed to refactor the controller so the page actually emits `url`s.

- [ ] **Step 3: Refactor the controller**

In `app/Http/Controllers/WerkzaamhedenController.php`:

Inject the builder:

```php
    public function __construct(
        private readonly RoadworkSearch $search,
        private readonly StructuredData $structuredData,
        private readonly FacetUrlBuilder $facetUrls,
    ) {}
```

Add `use App\Router\FacetUrlBuilder;`, `use App\Data\FacetGroup;`.

Change `render()` to accept the `ListingQuery` and pass its area OR-group into `browse`:

```php
    private function render(ListingQuery $query, string $term, string $sort, int $page): Response
    {
        $filters = $query->toFilters();
        $areaFilters = $query->toAreaFilters();
        $sortExpression = $sort === 'status' ? ['status_order:asc'] : ['start_ts:asc'];

        $main = $this->search->browse(
            $term, $filters, $sortExpression,
            ($page - 1) * self::PER_PAGE, self::PER_PAGE, [], $areaFilters,
        );

        $total = (int) ($main['estimatedTotalHits'] ?? 0);
        $cards = $this->hydrate(array_column($main['hits'] ?? [], 'id'));

        // ... structured data unchanged ...

        return Inertia::render('Werkzaamheden', [
            'results' => Inertia::merge($cards),
            'facets' => $this->facets($query, $term, $filters, $areaFilters),
            'filters' => [
                'q' => $term,
                'sort' => $sort,
            ],
            'total' => $total,
            'page' => $page,
            'hasMore' => $page * self::PER_PAGE < $total,
        ]);
    }
```

Update both entry points to build a `ListingQuery`. `__invoke` constructs one from the validated query string (mapping `status`→`addStatus`, `type`→`addType`, `gemeente`/`provincie` resolved to areas via the same model lookup as `FacetUrlBuilder`, `authority`→`addAuthority`); `renderFromQuery` passes its already-parsed `$query` straight through. Keep `__invoke`'s validation. For the area mapping in `__invoke`, resolve names through `Gemeente`/`Provincie` models (these only matter for back-compat query-string entry; the canonical entry is the clean URL).

Change `facets()` to return `FacetGroup`s built through `FacetUrlBuilder`. Its disjunctive distribution logic stays, but each group's option rows go through the builder. Replace the return block:

```php
    /**
     * @return array<string, FacetGroup>
     */
    private function facets(ListingQuery $query, string $term, array $filters, array $areaFilters): array
    {
        $distributions = [];
        foreach (self::FACETS as $group => $attribute) {
            // Drop this group's own selection (disjunctive counts).
            $withoutFilters = array_filter($filters, fn (string $k): bool => $k !== $attribute, ARRAY_FILTER_USE_KEY);
            $withoutArea = array_filter($areaFilters, fn (string $k): bool => $k !== $attribute, ARRAY_FILTER_USE_KEY);
            $raw = $this->search->browse($term, $withoutFilters, [], 0, 0, [$attribute], $withoutArea);
            $distributions[$group] = $raw['facetDistribution'][$attribute] ?? [];
        }

        return [
            'status' => new FacetGroup('status', 'Status', $this->facetUrls->options($query, 'status', $this->statusRows($distributions['status'], $filters['status_key'] ?? []))),
            'gemeente' => new FacetGroup('gemeente', 'Gemeente', $this->facetUrls->options($query, 'gemeente', $this->countRows($distributions['gemeente'], $areaFilters['gemeente'] ?? []))),
            'provincie' => new FacetGroup('provincie', 'Provincie', $this->facetUrls->options($query, 'provincie', $this->countRows($distributions['provincie'], $areaFilters['provincie'] ?? []))),
            'type' => new FacetGroup('type', 'Soort werk', $this->facetUrls->options($query, 'type', $this->countRows($distributions['type'], $filters['work_type'] ?? []))),
            'authority' => new FacetGroup('authority', 'Uitvoerder', $this->facetUrls->options($query, 'authority', $this->countRows($distributions['authority'], $filters['road_authority'] ?? []))),
        ];
    }
```

Rename the existing `statusOptions`/`countOptions` to `statusRows`/`countRows` and change their return to the raw row shape (`array{key,label,count,checked,dot?}` without `url`) — `FacetUrlBuilder` adds `url`. Their internals are otherwise unchanged (the `MAX_FACET_OPTIONS` cap and lifecycle ordering stay).

- [ ] **Step 4: Run the page + router suites**

Run: `php artisan test --compact tests/Feature/WerkzaamhedenPageTest.php && php artisan test --compact tests/Feature/Router`
Expected: PASS.

- [ ] **Step 5: Pint + regenerate types + commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan typescript:transform
git add app/Http/Controllers/WerkzaamhedenController.php tests/Feature/WerkzaamhedenPageTest.php resources/js/types
git commit -m "feat(werkzaamheden): emit FacetGroup DTOs with clean toggle URLs, area OR filter"
```

---

## Task 13: Werkzaamheden.vue navigates clean URLs

**Files:**
- Modify: `resources/js/pages/Werkzaamheden.vue`

**Interfaces:**
- Consumes: generated `App.Data.FacetGroup` / `App.Data.FacetOption`; `props.facets` is now `Record<string, App.Data.FacetGroup>`; `props.filters` is now `{ q: string; sort: string }`.
- Produces: facet clicks call `router.visit(option.url, …)`; chips reuse the selected options' urls; `q`/`sort`/`page` are query params layered on the current path via `window.location.pathname`.

**Design note:** Activate `inertia-vue-development` when editing this file. Remove the per-dimension `ref` selection arrays, `params()`, and the multi-select querystring logic. Facet state now comes entirely from the server props (each option's `checked` + `url`).

- [ ] **Step 1: Replace the script setup**

Rewrite the `<script setup>` of `resources/js/pages/Werkzaamheden.vue`:

```ts
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    results: App.Data.RoadworkCard[];
    facets: Record<string, App.Data.FacetGroup>;
    filters: { q: string; sort: string };
    total: number;
    page: number;
    hasMore: boolean;
}>();

const qInput = ref(props.filters.q);
const sortSel = ref(props.filters.sort);
const layout = ref<'list' | 'grid'>('list');

const groupOrder = ['status', 'gemeente', 'provincie', 'type', 'authority'];
const facetGroups = computed(() => groupOrder.map((key) => props.facets[key]).filter(Boolean));

const hasActive = computed(
    () =>
        qInput.value.trim().length > 0 ||
        facetGroups.value.some((g) => g.options.some((o) => o.checked)),
);

const countWord = computed(() => (props.total === 1 ? 'werkzaamheid' : 'werkzaamheden'));

/** Current pretty path; query refinements (q/sort/page) layer on top of it. */
function currentPath(): string {
    return window.location.pathname;
}

function query(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const payload: Record<string, string | number> = { ...extra };
    const q = qInput.value.trim();
    if (q) {
        payload.q = q;
    }
    if (sortSel.value !== 'start') {
        payload.sort = sortSel.value;
    }
    return payload;
}

/** Navigate to an option's precomputed clean URL, keeping q/sort. */
function go(url: string): void {
    router.get(url, query(), { preserveScroll: true, reset: ['results'] });
}

function applyQuery(): void {
    router.get(currentPath(), query(), { preserveState: true, preserveScroll: true, reset: ['results'] });
}

function loadMore(): void {
    router.get(currentPath(), query({ page: props.page + 1 }), {
        preserveState: true,
        preserveScroll: true,
        only: ['results', 'page', 'hasMore'],
    });
}

function clearAll(): void {
    qInput.value = '';
    router.get('/werkzaamheden', query({}), { preserveScroll: true, reset: ['results'] });
}

const chips = computed(() => {
    const list: { label: string; url: string }[] = [];
    for (const group of facetGroups.value) {
        for (const option of group.options) {
            if (option.checked) {
                list.push({ label: option.label, url: option.url });
            }
        }
    }
    return list;
});
```

- [ ] **Step 2: Update the template bindings**

In the same file's `<template>`:

- Facet group loop: iterate `facetGroups`, then `group.options`. Replace the checkbox `@change="toggle(group.model, option.key)"` with `@change="go(option.url)"` and `:checked="option.checked"`.
- Replace `group.title` (unchanged — `FacetGroup.title` exists), `option.dot`, `option.label`, `option.count` (all still present on `FacetOption`).
- Sort `<select>` `@change="applyQuery"` (was `applyFilters`).
- Search form `@submit.prevent="applyQuery"`.
- Active chips: `v-for="chip in chips"` with `@click="go(chip.url)"` (navigating to a selected option's url toggles it off).
- "Wis alles" / empty-state button: `@click="clearAll"`.
- "Meer laden": `@click="loadMore"`.

- [ ] **Step 3: Build the frontend**

Run: `npm run build`
Expected: builds with no TS errors. (If the project uses `composer run dev`/`npm run dev`, that's fine for local verification.)

- [ ] **Step 4: Type-check**

Run: `npm run types:check`
Expected: PASS (no `vue-tsc` errors; `App.Data.FacetGroup` resolves).

- [ ] **Step 5: Lint + commit**

```bash
npm run lint:check
git add resources/js/pages/Werkzaamheden.vue
git commit -m "feat(werkzaamheden): facets navigate to clean pretty URLs"
```

---

## Task 14: Reindex, full suite, manual verification

**Files:** none (operational task).

- [ ] **Step 1: Regenerate area slugs**

The slug scheme changed (global uniqueness). Run the project's slug rebuild path. If it's an Artisan command, find it:

Run: `php artisan list | grep -i slug`
Then run the relevant rebuild command (it calls `AreaSlugGenerator::rebuild()`). If none exists, rebuild via tinker:
`php artisan tinker --execute 'app(\App\Router\AreaSlugGenerator::class)->rebuild();'`

- [ ] **Step 2: Reindex Meilisearch**

Area filtering is unchanged in attribute names, but reindex to be safe after any model/slug touch (per the meilisearch-workflow memory):

Run: `php artisan scout:import "App\Models\Roadwork"`
Expected: import completes.

- [ ] **Step 3: Run the full router + data + roadworks suites**

Run: `php artisan test --compact tests/Feature/Router tests/Unit/Router tests/Unit/Data tests/Unit/Roadworks`
Expected: all PASS.

- [ ] **Step 4: Run the entire suite**

Run: `php artisan test --compact`
Expected: all PASS. Investigate any failure referencing `area()`/`setArea` (leftover call sites) and fix.

- [ ] **Step 5: Manual smoke (ask the user to run dev server)**

Verify in the browser:
- `/werkzaamheden` renders facets with counts.
- Clicking a gemeente navigates to `/{gemeente-slug}`.
- Clicking a second gemeente → `/{a},{b}` (sorted).
- Adding a status → `/{areas}/{status}`.
- A chip click removes that value and updates the URL.
- `/noord-holland/{gemeente}` 301-redirects to the single-segment canonical.

Commit nothing here unless fixes were needed.

---

## Self-Review

- **Spec coverage:** grammar (Tasks 2–7), global-unique slugs + history (Task 5), canonical single-segment (Tasks 6–8), area OR filter semantics (Tasks 1, 9, 12), FacetUrlBuilder extraction (Task 11), FacetOption/Group DTO replacing the hand-written TS (Tasks 10, 12, 13), Vue clean-URL navigation (Task 13), no querystring facets / q-sort-page only (Tasks 12–13), reindex (Task 14). Covered.
- **Type consistency:** `addArea/areas/removeAreaByName/hasAreaName`, `toFilters`/`toAreaFilters`, `browse(..., array $areaFilters)`, `buildFilter`, `FacetUrlBuilder::options`, `FacetOption(key,label,count,checked,url,dot?)`, `FacetGroup(key,title,options)` — used consistently across Tasks 1, 9, 10, 11, 12, 13.
- **Known limitation (documented):** wijk/buurt areas resolve in URLs but are dropped from Meili filtering (`toAreaFilters` only maps gemeente/provincie, the only indexed area facets). Area-name ambiguity in facet toggling adds all same-named area rows (consistent with name-based filtering).
