# SEO-friendly Slug URLs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve every roadwork detail page at a single-segment SEO URL `/{municipality}-{title}`, with old slugs 301-redirecting to the current one.

**Architecture:** A dedicated, non-versioned `roadwork_slugs` table holds one *current* slug per roadwork plus historical slugs (redirect targets). Slugs are (re)generated from the same title the page displays whenever the feed is upserted. A one-segment catch-all route resolves the slug, falling back to 404. The slug is surfaced through the DTOs and the Meilisearch document so every link uses it.

**Tech Stack:** Laravel 13, PostgreSQL + PostGIS (temporal_tables trigger), Inertia v3 + Vue 3, Laravel Scout + Meilisearch, Wayfinder, PHPUnit 12.

## Global Constraints

- PHP 8.5; use constructor property promotion, explicit return types, typed params, curly braces always.
- `roadworks` is a **temporal table** — never add slug columns to it; the `sys_period` trigger versions every change. The `roadwork_slugs` table is **not** versioned (no trigger).
- Data is written **only** through `App\Roadworks\RoadworkUpserter` (raw SQL upsert). `Roadwork` has `$timestamps = false`.
- Tests use real Postgres + `RefreshDatabase`, create rows via `app(RoadworkUpserter::class)->upsert(...)`, and assert Inertia with `Inertia\Testing\AssertableInertia`. No Meilisearch is contacted in these tests (upsert is raw SQL, not Eloquent `save`). `SCOUT_PREFIX=testing_` is set in `phpunit.xml`.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- Run tests with `php artisan test --compact --filter=<name>`.
- The slug title MUST equal the title shown on the page/cards — both derive from one shared helper.

---

### Task 1: `roadwork_slugs` table

**Files:**
- Create: `database/migrations/2026_06_27_000000_create_roadwork_slugs_table.php`
- Test: `tests/Feature/Roadworks/RoadworkSlugsSchemaTest.php`

**Interfaces:**
- Produces: table `roadwork_slugs(id, roadwork_id, slug UNIQUE, is_current, created_at)`; partial unique index `(roadwork_id) WHERE is_current`; FK `roadwork_id -> roadworks(id) ON DELETE CASCADE`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoadworkSlugsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_roadwork_slugs_table_exists_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('roadwork_slugs'));
        $this->assertTrue(Schema::hasColumns('roadwork_slugs', ['id', 'roadwork_id', 'slug', 'is_current', 'created_at']));
    }

    public function test_only_one_current_slug_per_roadwork_is_allowed(): void
    {
        $id = DB::table('roadworks')->insertGetId([
            'source' => 'DATEX', 'source_id' => 'SCHEMA_1', 'feature' => '{}',
        ], 'id');

        DB::table('roadwork_slugs')->insert(['roadwork_id' => $id, 'slug' => 'a', 'is_current' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('roadwork_slugs')->insert(['roadwork_id' => $id, 'slug' => 'b', 'is_current' => true]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RoadworkSlugsSchemaTest`
Expected: FAIL — table `roadwork_slugs` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Routing slugs for roadworks. Deliberately NOT versioned (no temporal trigger):
 * slug churn must not bloat roadworks history. One `is_current` slug per roadwork
 * is the canonical URL; the rest are historical redirect targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE roadwork_slugs (
                id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                roadwork_id bigint NOT NULL REFERENCES roadworks(id) ON DELETE CASCADE,
                slug        varchar(255) NOT NULL,
                is_current  boolean NOT NULL DEFAULT false,
                created_at  timestamptz NOT NULL DEFAULT current_timestamp,
                CONSTRAINT roadwork_slugs_slug_unique UNIQUE (slug)
            );

            CREATE UNIQUE INDEX roadwork_slugs_one_current
                ON roadwork_slugs (roadwork_id) WHERE is_current;
            CREATE INDEX roadwork_slugs_roadwork_id_index ON roadwork_slugs (roadwork_id);
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS roadwork_slugs;');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RoadworkSlugsSchemaTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_27_000000_create_roadwork_slugs_table.php tests/Feature/Roadworks/RoadworkSlugsSchemaTest.php
git commit -m "feat: add roadwork_slugs table"
```

---

### Task 2: Shared `RoadworkTitle` helper (dedupe title logic)

**Files:**
- Create: `app/Roadworks/RoadworkTitle.php`
- Modify: `app/Roadworks/Data/ProjectDetail.php` (remove local `title`/`description`/`descriptionParts`, delegate)
- Modify: `app/Roadworks/Data/RoadworkCard.php` (same)
- Test: `tests/Unit/Roadworks/RoadworkTitleTest.php`

**Interfaces:**
- Produces:
  - `RoadworkTitle::parts(Roadwork $roadwork): list<string>` — trimmed, de-duplicated, non-empty comma parts of `causeDescription`.
  - `RoadworkTitle::for(Roadwork $roadwork): string` — last part, else `"{road_authority} – {kind}"` fallback (trimmed of ` –`), else `"Wegwerkzaamheden"`.

> Note: `ProjectDetail` and `RoadworkCard` currently contain identical `descriptionParts()` and `title()`. Both delegate to this helper after the change. Their *description* strings differ (`·`-joined parts with different fallbacks) — leave each DTO's `description()` as-is; only `title()`/`descriptionParts()` move.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkTitle;
use PHPUnit\Framework\TestCase;

class RoadworkTitleTest extends TestCase
{
    private function roadwork(?string $cause, ?string $authority = null, ?string $kind = null): Roadwork
    {
        $rw = new Roadwork();
        $rw->setRawAttributes([
            'road_authority' => $authority,
            'kind' => $kind,
            'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => $cause]]]),
        ], true);

        return $rw;
    }

    public function test_title_is_last_comma_part(): void
    {
        $this->assertSame('GAS Hoofdstraat', RoadworkTitle::for($this->roadwork('Kabels / Leidingen, , GAS Hoofdstraat')));
    }

    public function test_title_falls_back_to_authority_and_kind(): void
    {
        $this->assertSame("Gemeente 's-Gravenhage – WORK", RoadworkTitle::for($this->roadwork(null, "Gemeente 's-Gravenhage", 'WORK')));
    }

    public function test_parts_are_trimmed_and_deduped(): void
    {
        $this->assertSame(['Overig', 'Kademuur'], RoadworkTitle::parts($this->roadwork('Overig, , Kademuur, Overig')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RoadworkTitleTest`
Expected: FAIL — class `App\Roadworks\RoadworkTitle` not found.

- [ ] **Step 3: Create the helper**

```php
<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;

/**
 * Single source of truth for a roadwork's display title, derived from Melvin's
 * comma-packed `causeDescription`. Used by the detail page, the cards, AND the
 * slug generator so the URL can never drift from the shown title.
 */
final class RoadworkTitle
{
    /**
     * @return list<string>
     */
    public static function parts(Roadwork $roadwork): array
    {
        $raw = data_get($roadwork->feature, 'situation.properties.causeDescription');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    public static function for(Roadwork $roadwork): string
    {
        $parts = self::parts($roadwork);

        if ($parts !== []) {
            return $parts[count($parts) - 1];
        }

        return trim(($roadwork->road_authority ?? 'Wegwerkzaamheden').' – '.($roadwork->kind ?? ''), ' –');
    }
}
```

- [ ] **Step 4: Delegate from both DTOs**

In `app/Roadworks/Data/ProjectDetail.php`: delete the private `descriptionParts()` and `title()` methods; replace their callers:
- `title: self::title($roadwork)` → `title: RoadworkTitle::for($roadwork)`
- inside `description()`, `$parts = self::descriptionParts($roadwork);` → `$parts = RoadworkTitle::parts($roadwork);`
- add `use App\Roadworks\RoadworkTitle;`

In `app/Roadworks/Data/RoadworkCard.php`: identical change — delete local `title()` and `descriptionParts()`, route `title:` and `description()`'s `$parts` through `RoadworkTitle`, add the `use`.

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --compact --filter="RoadworkTitleTest|ProjectPageTest"`
Expected: PASS (existing `ProjectPageTest` still asserts `project.title = 'GAS Hoofdstraat'`).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Roadworks/RoadworkTitle.php app/Roadworks/Data/ProjectDetail.php app/Roadworks/Data/RoadworkCard.php tests/Unit/Roadworks/RoadworkTitleTest.php
git commit -m "refactor: extract shared RoadworkTitle helper"
```

---

### Task 3: `RoadworkSlugger` (slug generation + collisions)

**Files:**
- Create: `app/Roadworks/RoadworkSlugger.php`
- Test: `tests/Feature/Roadworks/RoadworkSluggerTest.php`

**Interfaces:**
- Consumes: `RoadworkTitle::for()` (Task 2); `roadwork_slugs` table (Task 1).
- Produces:
  - `RoadworkSlugger::base(Roadwork $roadwork): string` — `Str::slug("{authority-without-prefix} {title}")`, prefix = `Gemeente|Provincie|Waterschap|Rijkswaterstaat`; empty authority → `nederland`.
  - `RoadworkSlugger::unique(string $base, int $roadworkId): string` — appends `-2`,`-3`,… while the candidate is taken by a *different* roadwork in `roadwork_slugs`.

> This test creates roadworks with `RoadworkUpserter`. Because Task 4 wires the synchronizer into the upserter, by then upsert auto-creates slugs. To test the slugger in isolation here (before Task 4), build the `Roadwork` model directly and insert a clashing slug row by hand.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkSlugger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkSluggerTest extends TestCase
{
    use RefreshDatabase;

    private function roadwork(string $cause, ?string $authority): Roadwork
    {
        $rw = new Roadwork();
        $rw->setRawAttributes([
            'road_authority' => $authority,
            'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => $cause]]]),
        ], true);

        return $rw;
    }

    public function test_base_strips_prefix_and_slugifies(): void
    {
        $slugger = app(RoadworkSlugger::class);
        $this->assertSame('s-gravenhage-gas-hoofdstraat', $slugger->base($this->roadwork('GAS Hoofdstraat', "Gemeente 's-Gravenhage")));
    }

    public function test_base_falls_back_to_nederland_without_authority(): void
    {
        $slugger = app(RoadworkSlugger::class);
        $this->assertSame('nederland-kademuur', $slugger->base($this->roadwork('Kademuur', null)));
    }

    public function test_unique_appends_counter_only_on_collision(): void
    {
        $other = DB::table('roadworks')->insertGetId(['source' => 'X', 'source_id' => 'OTHER', 'feature' => '{}'], 'id');
        DB::table('roadwork_slugs')->insert(['roadwork_id' => $other, 'slug' => 'utrecht-n201', 'is_current' => true]);

        $slugger = app(RoadworkSlugger::class);
        $this->assertSame('utrecht-n201-2', $slugger->unique('utrecht-n201', 999));
        $this->assertSame('utrecht-n201', $slugger->unique('utrecht-n201', $other), 'own slug is not a collision');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RoadworkSluggerTest`
Expected: FAIL — class `App\Roadworks\RoadworkSlugger` not found.

- [ ] **Step 3: Create the slugger**

```php
<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the SEO slug `{municipality}-{title}` for a roadwork and guarantees it
 * is unique across {@see roadwork_slugs}. A roadwork keeping one of its own
 * existing slugs is never treated as a collision.
 */
final class RoadworkSlugger
{
    public function base(Roadwork $roadwork): string
    {
        $authority = $roadwork->road_authority;
        $municipality = $authority === null || trim($authority) === ''
            ? 'nederland'
            : (string) preg_replace('/^(Gemeente|Provincie|Waterschap|Rijkswaterstaat)\s+/i', '', trim($authority));

        $slug = Str::slug(trim($municipality.' '.RoadworkTitle::for($roadwork)));

        return $slug === '' ? 'nederland' : $slug;
    }

    public function unique(string $base, int $roadworkId): string
    {
        $candidate = $base;
        $suffix = 1;

        while ($this->takenByOther($candidate, $roadworkId)) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    private function takenByOther(string $slug, int $roadworkId): bool
    {
        return DB::table('roadwork_slugs')
            ->where('slug', $slug)
            ->where('roadwork_id', '!=', $roadworkId)
            ->exists();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RoadworkSluggerTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Roadworks/RoadworkSlugger.php tests/Feature/Roadworks/RoadworkSluggerTest.php
git commit -m "feat: add RoadworkSlugger"
```

---

### Task 4: Sync slugs on upsert + backfill command

**Files:**
- Create: `app/Roadworks/RoadworkSlugSynchronizer.php`
- Create: `app/Console/Commands/BackfillRoadworkSlugs.php`
- Modify: `app/Roadworks/RoadworkUpserter.php` (return row `id`; call synchronizer)
- Test: `tests/Feature/Roadworks/RoadworkSlugSyncTest.php`

**Interfaces:**
- Consumes: `RoadworkSlugger` (Task 3); `Roadwork` model.
- Produces: `RoadworkSlugSynchronizer::sync(int $roadworkId): void` — ensures the roadwork has exactly one `is_current` slug equal to the freshly-computed desired slug; demotes the previous current slug to a historical (redirect) row; reuses an existing historical row for the same slug instead of inserting a duplicate.

> `RoadworkUpserter::upsert()` keeps returning `bool` (inserted vs updated) — its callers rely on that. Internally it now also captures the row id from `RETURNING` to drive the sync.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkSlugSyncTest extends TestCase
{
    use RefreshDatabase;

    private function upsert(string $cause): Roadwork
    {
        $point = ['type' => 'Point', 'coordinates' => [4.3, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => ['causeDescription' => $cause]], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', 'NDW_SYNC_1',
            ['kind' => 'WORK', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true],
            $point, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        return Roadwork::where('source_id', 'NDW_SYNC_1')->firstOrFail();
    }

    public function test_upsert_creates_one_current_slug(): void
    {
        $rw = $this->upsert('GAS Hoofdstraat');

        $current = DB::table('roadwork_slugs')->where('roadwork_id', $rw->id)->where('is_current', true)->first();
        $this->assertNotNull($current);
        $this->assertSame('s-gravenhage-gas-hoofdstraat', $current->slug);
    }

    public function test_title_change_demotes_old_slug_to_redirect(): void
    {
        $rw = $this->upsert('GAS Hoofdstraat');
        $this->upsert('Riolering Vervangen'); // same source_id => update, new title

        $rows = DB::table('roadwork_slugs')->where('roadwork_id', $rw->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('s-gravenhage-gas-hoofdstraat', $rows[0]->slug);
        $this->assertFalse((bool) $rows[0]->is_current);
        $this->assertSame('s-gravenhage-riolering-vervangen', $rows[1]->slug);
        $this->assertTrue((bool) $rows[1]->is_current);
    }

    public function test_unchanged_title_does_not_create_new_slug(): void
    {
        $rw = $this->upsert('GAS Hoofdstraat');
        $this->upsert('GAS Hoofdstraat');

        $this->assertSame(1, DB::table('roadwork_slugs')->where('roadwork_id', $rw->id)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RoadworkSlugSyncTest`
Expected: FAIL — no slug rows created (synchronizer not wired yet).

- [ ] **Step 3: Create the synchronizer**

```php
<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles a roadwork's slugs after its content changes: computes the desired
 * current slug and, when it differs, demotes the old current slug to a
 * historical redirect row and promotes (or reuses) the new one.
 */
final class RoadworkSlugSynchronizer
{
    public function __construct(private readonly RoadworkSlugger $slugger) {}

    public function sync(int $roadworkId): void
    {
        $roadwork = Roadwork::find($roadworkId);

        if ($roadwork === null) {
            return;
        }

        $desired = $this->slugger->unique($this->slugger->base($roadwork), $roadworkId);

        $current = DB::table('roadwork_slugs')
            ->where('roadwork_id', $roadworkId)
            ->where('is_current', true)
            ->first();

        if ($current !== null && $current->slug === $desired) {
            return;
        }

        DB::transaction(function () use ($roadworkId, $desired): void {
            DB::table('roadwork_slugs')
                ->where('roadwork_id', $roadworkId)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $existing = DB::table('roadwork_slugs')
                ->where('roadwork_id', $roadworkId)
                ->where('slug', $desired)
                ->first();

            if ($existing !== null) {
                DB::table('roadwork_slugs')->where('id', $existing->id)->update(['is_current' => true]);
            } else {
                DB::table('roadwork_slugs')->insert([
                    'roadwork_id' => $roadworkId,
                    'slug' => $desired,
                    'is_current' => true,
                ]);
            }
        });
    }
}
```

- [ ] **Step 4: Wire the synchronizer into the upserter**

In `app/Roadworks/RoadworkUpserter.php`:
- Add a constructor: `public function __construct(private readonly RoadworkSlugSynchronizer $slugs) {}` and `use App\Roadworks\RoadworkSlugSynchronizer;`.
- Change the `RETURNING` clause to also yield the id:

```php
                RETURNING id, (xmax = 0) AS inserted
```

- After the `roadwork_seen` upsert and before `return`, sync the slug:

```php
        $this->slugs->sync((int) $row->id);

        return (bool) $row->inserted;
```

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --compact --filter="RoadworkSlugSyncTest|RoadworkUpserterTest"`
Expected: PASS. (`RoadworkUpserterTest` still passes — the `bool` return is unchanged.)

- [ ] **Step 6: Create the backfill command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Roadwork;
use App\Roadworks\RoadworkSlugSynchronizer;
use Illuminate\Console\Command;

class BackfillRoadworkSlugs extends Command
{
    protected $signature = 'roadworks:backfill-slugs';

    protected $description = 'Generate current slugs for all roadworks (idempotent).';

    public function handle(RoadworkSlugSynchronizer $synchronizer): int
    {
        $count = 0;

        Roadwork::query()->select('id')->chunkById(500, function ($roadworks) use ($synchronizer, &$count): void {
            foreach ($roadworks as $roadwork) {
                $synchronizer->sync((int) $roadwork->id);
                $count++;
            }
        });

        $this->info("Synced slugs for {$count} roadworks.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Write + run the backfill test**

Append to `tests/Feature/Roadworks/RoadworkSlugSyncTest.php`:

```php
    public function test_backfill_assigns_slugs_and_is_idempotent(): void
    {
        DB::table('roadworks')->insert([
            ['source' => 'X', 'source_id' => 'B1', 'road_authority' => 'Gemeente Venlo',
             'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => 'Brug']]])],
        ]);

        $this->artisan('roadworks:backfill-slugs')->assertSuccessful();
        $this->artisan('roadworks:backfill-slugs')->assertSuccessful();

        $this->assertSame(1, DB::table('roadwork_slugs')->where('slug', 'venlo-brug')->where('is_current', true)->count());
    }
```

Run: `php artisan test --compact --filter=RoadworkSlugSyncTest`
Expected: PASS (all four tests).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Roadworks/RoadworkSlugSynchronizer.php app/Console/Commands/BackfillRoadworkSlugs.php app/Roadworks/RoadworkUpserter.php tests/Feature/Roadworks/RoadworkSlugSyncTest.php
git commit -m "feat: sync roadwork slugs on upsert + backfill command"
```

---

### Task 5: `RoadworkSlug` model, relation, and Meilisearch field

**Files:**
- Create: `app/Models/RoadworkSlug.php`
- Modify: `app/Models/Roadwork.php` (relation, `toSearchableArray`, `makeAllSearchableUsing`)
- Test: `tests/Feature/Roadworks/RoadworkSearchableTest.php` (add a case)

**Interfaces:**
- Produces:
  - `RoadworkSlug` Eloquent model on table `roadwork_slugs`, `$timestamps = false`, `currentScope` not needed.
  - `Roadwork::currentSlug(): HasOne` — the `is_current` slug.
  - `Roadwork::toSearchableArray()` now contains `'slug' => $this->currentSlug?->slug`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Roadworks/RoadworkSearchableTest.php`:

```php
    public function test_searchable_array_includes_current_slug(): void
    {
        $point = ['type' => 'Point', 'coordinates' => [4.3, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => ['causeDescription' => 'GAS Hoofdstraat']], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', 'NDW_SLUG_1',
            ['kind' => 'WORK', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true],
            $point, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        $roadwork = Roadwork::with('currentSlug')->where('source_id', 'NDW_SLUG_1')->firstOrFail();

        $this->assertSame('s-gravenhage-gas-hoofdstraat', $roadwork->toSearchableArray()['slug']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_searchable_array_includes_current_slug`
Expected: FAIL — relation `currentSlug` / key `slug` missing.

- [ ] **Step 3: Create the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Model;

#[Guarded(['id'])]
class RoadworkSlug extends Model
{
    protected $table = 'roadwork_slugs';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }
}
```

- [ ] **Step 4: Add the relation + Scout field**

In `app/Models/Roadwork.php`:
- add `use Illuminate\Database\Eloquent\Relations\HasOne;`
- add the relation:

```php
    public function currentSlug(): HasOne
    {
        return $this->hasOne(RoadworkSlug::class)->where('is_current', true);
    }
```

- in `toSearchableArray()`, add to the `$document` array (after `'road_authority' => ...`):

```php
            'slug' => $this->currentSlug?->slug,
```

- in `makeAllSearchableUsing()`, eager-load the slug:

```php
        return $query->withRepresentativePoint()->with('currentSlug');
```

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --compact --filter=RoadworkSearchableTest`
Expected: PASS (existing + new case).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/RoadworkSlug.php app/Models/Roadwork.php tests/Feature/Roadworks/RoadworkSearchableTest.php
git commit -m "feat: expose current slug on Roadwork + Meili document"
```

> **After deploy:** run `php artisan scout:import "App\Models\Roadwork"` so existing Meili documents pick up the new `slug` field (per the project's reindex-after-toSearchableArray-change workflow).

---

### Task 6: Slug routing + controller resolution

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/ProjectController.php` (rename `show` → `showBySlug`; add `redirectFromId`)
- Modify: `tests/Feature/Roadworks/ProjectPageTest.php`

**Interfaces:**
- Consumes: `RoadworkSlug` (Task 5); `ProjectDetail` (Task 7 adds `slug`, but render works before that too).
- Produces: route `projecten.show` now `/{slug}` (was `/projecten/{id}`); `/projecten/{id}` becomes a permanent redirect.

- [ ] **Step 1: Rewrite the controller**

Replace `ProjectController::show()` with:

```php
    public function showBySlug(string $slug): Response|RedirectResponse
    {
        $slugRow = RoadworkSlug::where('slug', $slug)->first();

        if ($slugRow === null) {
            abort(404);
        }

        if (! $slugRow->is_current) {
            $current = RoadworkSlug::where('roadwork_id', $slugRow->roadwork_id)
                ->where('is_current', true)
                ->firstOrFail();

            return redirect()->route('projecten.show', $current->slug, 301);
        }

        $roadwork = Roadwork::query()
            ->withRepresentativePoint()
            ->with('currentSlug')
            ->findOrFail($slugRow->roadwork_id);

        return Inertia::render('Projecten/Show', [
            'project' => ProjectDetail::fromModel($roadwork),
        ]);
    }

    public function redirectFromId(int $id): RedirectResponse
    {
        $current = RoadworkSlug::where('roadwork_id', $id)
            ->where('is_current', true)
            ->firstOrFail();

        return redirect()->route('projecten.show', $current->slug, 301);
    }
```

Add imports: `use App\Models\RoadworkSlug;`, `use Illuminate\Http\RedirectResponse;`.

- [ ] **Step 2: Update routes**

In `routes/web.php` replace the `/projecten/{id}` block with the id-redirect, and add the catch-all **as the last route in the file** (after the tiles route):

```php
Route::get('/projecten/{id}', [ProjectController::class, 'redirectFromId'])
    ->whereNumber('id')
    ->name('projecten.legacy');

// Single-segment SEO detail pages. MUST stay last so named routes win.
Route::get('/{slug}', [ProjectController::class, 'showBySlug'])
    ->where('slug', '[a-z0-9-]+')
    ->name('projecten.show');
```

- [ ] **Step 3: Rewrite the page test**

Replace the body of `tests/Feature/Roadworks/ProjectPageTest.php` with slug-based cases (keep the upsert helper shape):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectPageTest extends TestCase
{
    use RefreshDatabase;

    private function upsert(string $sourceId, string $cause): Roadwork
    {
        $line = ['type' => 'LineString', 'coordinates' => [[4.89, 52.37], [4.90, 52.37]]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $line, 'properties' => ['causeDescription' => $cause]], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', $sourceId,
            ['kind' => 'WORK', 'severity' => 'high', 'status' => 'running', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true, 'start_date' => '2026-07-01T00:00:00Z', 'end_date' => '2026-09-01T00:00:00Z'],
            $line, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        return Roadwork::where('source_id', $sourceId)->firstOrFail();
    }

    public function test_current_slug_renders_project(): void
    {
        $this->upsert('NDW_PAGE_1', 'Kabels / Leidingen, , GAS Hoofdstraat');

        $this->get('/s-gravenhage-gas-hoofdstraat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projecten/Show')
                ->where('project.title', 'GAS Hoofdstraat')
                ->where('project.authority', "Gemeente 's-Gravenhage")
            );
    }

    public function test_historical_slug_redirects_to_current(): void
    {
        $this->upsert('NDW_PAGE_1', 'GAS Hoofdstraat');
        $this->upsert('NDW_PAGE_1', 'Riolering Vervangen');

        $this->get('/s-gravenhage-gas-hoofdstraat')
            ->assertRedirect('/s-gravenhage-riolering-vervangen')
            ->assertStatus(301);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->get('/this-slug-does-not-exist')->assertNotFound();
    }

    public function test_legacy_numeric_url_redirects_to_slug(): void
    {
        $rw = $this->upsert('NDW_PAGE_1', 'GAS Hoofdstraat');

        $this->get("/projecten/{$rw->id}")
            ->assertRedirect('/s-gravenhage-gas-hoofdstraat')
            ->assertStatus(301);
    }

    public function test_named_routes_still_resolve(): void
    {
        $this->get('/kaart')->assertOk();
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --compact --filter=ProjectPageTest`
Expected: PASS (all cases).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/web.php app/Http/Controllers/ProjectController.php tests/Feature/Roadworks/ProjectPageTest.php
git commit -m "feat: resolve detail pages by slug with legacy + history redirects"
```

> **Reserved-slug note:** a roadwork whose slug collides with a named first-segment path (e.g. `kaart`) would be shadowed by that route. Acceptable given the `{municipality}-{title}` shape makes this near-impossible; revisit with a reserved-word guard only if it occurs.

---

### Task 7: Surface slug through DTOs and search API

**Files:**
- Modify: `app/Roadworks/Data/ProjectDetail.php` (add `slug`)
- Modify: `app/Roadworks/Data/RoadworkCard.php` (add `slug`)
- Modify: `app/Http/Controllers/HomeController.php` (eager-load `currentSlug`)
- Modify: `app/Http/Controllers/RoadworkSearchController.php` (add `slug` to feature props)
- Test: `tests/Feature/Roadworks/ProjectPageTest.php` (assert slug prop), `tests/Feature/Roadworks/RoadworkSearchApiTest.php` (assert slug in props)

**Interfaces:**
- Consumes: `Roadwork::currentSlug` (Task 5); Meili `slug` field (Task 5).
- Produces: `ProjectDetail->slug`, `RoadworkCard->slug`; each map feature's `properties.slug`.

- [ ] **Step 1: Add `slug` to `ProjectDetail`**

- add constructor param `public ?string $slug,` (after `$longitude` is fine).
- in `fromModel`, add `slug: $roadwork->currentSlug?->slug,`.

- [ ] **Step 2: Add `slug` to `RoadworkCard`**

- add constructor param `public ?string $slug,`.
- in `fromModel`, add `slug: $roadwork->currentSlug?->slug,`.

- [ ] **Step 3: Eager-load slug where cards/detail are built**

- `HomeController::__invoke` query: add `->with('currentSlug')` to the `Roadwork::query()` chain.
- (`ProjectController::showBySlug` already eager-loads `currentSlug` from Task 6.)

- [ ] **Step 4: Add `slug` to search feature props**

In `RoadworkSearchController::toFeatures()`, inside the `'properties' => [...]` array add:

```php
                    'slug' => $hit['slug'] ?? null,
```

- [ ] **Step 5: Assert the props (extend existing tests)**

Add to `ProjectPageTest::test_current_slug_renders_project` the assertion:

```php
                ->where('project.slug', 's-gravenhage-gas-hoofdstraat')
```

In `tests/Feature/Roadworks/RoadworkSearchApiTest.php`, find an existing test that asserts a feature's `properties` and add an assertion that `slug` is present for a hit (mirror that file's existing fixture/seeding pattern — it seeds Meili via the project's helper; assert `properties.slug` is a non-empty string for the returned feature).

- [ ] **Step 6: Run tests to verify pass**

Run: `php artisan test --compact --filter="ProjectPageTest|RoadworkSearchApiTest"`
Expected: PASS.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Roadworks/Data/ProjectDetail.php app/Roadworks/Data/RoadworkCard.php app/Http/Controllers/HomeController.php app/Http/Controllers/RoadworkSearchController.php tests/Feature/Roadworks/ProjectPageTest.php tests/Feature/Roadworks/RoadworkSearchApiTest.php
git commit -m "feat: expose slug through DTOs and search API"
```

---

### Task 8: Frontend links use the slug

**Files:**
- Modify: `resources/js/pages/Home.vue` (featured + grid links)
- Modify: `resources/js/pages/Kaart.vue` (map detail panel link)
- Regenerate: `resources/js/routes/projecten/index.ts` (via `php artisan wayfinder:generate`)

**Interfaces:**
- Consumes: `project.slug` / `featured.slug` (RoadworkCard, Task 7); `selected.slug` (map feature props, Task 7).

- [ ] **Step 1: Update Home.vue links**

- Featured (`Home.vue:87`): `:href="`/projecten/${featured.id}`"` → `:href="`/${featured.slug}`"`.
- Grid (`Home.vue:132`): `:href="`/projecten/${project.id}`"` → `:href="`/${project.slug}`"`.

- [ ] **Step 2: Update Kaart.vue link**

- (`Kaart.vue:212`): `:href="`/projecten/${selected.id}`"` → `:href="`/${selected.slug}`"`.

> The map's `selected` comes from a Meili feature's `properties`; Task 7 added `slug` there. If `selected` is strongly typed in the component, add `slug?: string` to that type.

- [ ] **Step 3: Regenerate Wayfinder + build**

Run:
```bash
php artisan wayfinder:generate
npm run build
```
Expected: `resources/js/routes/projecten/index.ts` now defines `show` against `/{slug}`; build succeeds. (If `npm`/`node` misbehave, use `/opt/homebrew/bin`; if rolldown native binding errors, `npm install` then rebuild.)

- [ ] **Step 4: Run the full backend suite**

Run: `php artisan test --compact`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Home.vue resources/js/pages/Kaart.vue resources/js/routes/projecten/index.ts
git commit -m "feat: link detail pages by slug"
```

---

## Self-Review

**Spec coverage:**
- Slug table (not column) → Task 1. ✅
- Regenerate + history → Task 4 (`sync` demotes old to historical). ✅
- Counter only on collision → Task 3 (`unique`). ✅
- Build redirects now → Task 6 (historical 301 + legacy id 301). ✅
- Index: DB unique + Meili field → Task 1 (unique) + Task 5 (Meili). ✅
- Shared title helper → Task 2. ✅
- `/{slug}` one level deep, 404 preserved → Task 6. ✅
- Slug into DTOs + frontend links → Tasks 7–8. ✅
- Backfill → Task 4. ✅

**Placeholder scan:** Task 7 Step 5 references the search-API test's existing fixture pattern rather than reproducing it — the implementer must open `RoadworkSearchApiTest.php` to mirror its Meili seeding (that file wasn't read while writing this plan). All other steps carry complete code.

**Type consistency:** `currentSlug` relation, `RoadworkSlug` model, `slug` prop, `sync(int)`, `base()/unique()` names are used identically across tasks. `projecten.show` route name reused by `redirect()->route()` calls and Wayfinder.
