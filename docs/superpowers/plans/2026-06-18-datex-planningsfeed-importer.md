# DATEX Planningsfeed Importer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Import the NDW open-data DATEX II `planningsfeed_wegwerkzaamheden_en_evenementen` feed into the existing `roadworks` table as a CC0, auth-free source (`source='DATEX'`) alongside the Melvin importer.

**Architecture:** A streaming `XMLReader` reader yields one `<sit:situation>` at a time; a pure mapper normalizes each into the existing `{situation, restrictions, detours}` jsonb document plus promoted columns; a shared upserter writes rows on `(source, source_id)`, with the temporal trigger keeping history. Never deletes — `first_seen_at`/`last_seen_at` track liveness.

**Tech Stack:** Laravel 13, PHP 8.3+, PostgreSQL + PostGIS, spatie/laravel-data, PHPUnit 12, Laravel Sail (tests run on the pgsql `testing` DB).

> **All test/artisan commands run inside Sail** (host can't reach pgsql): prefix with `./vendor/bin/sail`. Example: `./vendor/bin/sail artisan test --filter=...`.

---

## File Structure

- `database/migrations/2026_06_18_060100_create_roadworks_table.php` — **modify**: add promoted columns before the history `LIKE`.
- `app/Roadworks/RoadworkUpserter.php` — **create**: one shared upsert path (Melvin + DATEX).
- `app/Roadworks/RoadworksImporter.php` — **modify**: call the shared upserter.
- `app/Roadworks/Data/RoadworkDocument.php` — **modify**: add `attachments`.
- `app/Roadworks/Datex/MappedRoadwork.php` — **create**: mapper output value object.
- `app/Roadworks/Datex/DatexSituationMapper.php` — **create**: situation XML → `MappedRoadwork`.
- `app/Roadworks/Datex/DatexFeedReader.php` — **create**: streaming gz + `XMLReader` generator.
- `app/Console/Commands/ImportDatexRoadworks.php` — **create**: `roadworks:import:datex {file?}`.
- `tests/Fixtures/datex/sample.xml` — **create**: small DATEX fixture.
- `tests/Unit/Datex/DatexSituationMapperTest.php` — **create**.
- `tests/Feature/Datex/ImportDatexRoadworksTest.php` — **create**.

---

## Task 1: Add promoted columns to the roadworks schema

**Files:**
- Modify: `database/migrations/2026_06_18_060100_create_roadworks_table.php`
- Test: `tests/Feature/Datex/RoadworksSchemaTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Datex/RoadworksSchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoadworksSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_roadworks_has_datex_promoted_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('roadworks', [
            'kind', 'severity', 'hindrance', 'road_authority',
            'start_date', 'end_date', 'first_seen_at', 'last_seen_at',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=RoadworksSchemaTest`
Expected: FAIL (columns `kind`, `severity`, … do not exist).

- [ ] **Step 3: Add the columns to the create-table migration**

In `database/migrations/2026_06_18_060100_create_roadworks_table.php`, replace the `CREATE TABLE roadworks (...)` column list so it includes the new columns **before** the `roadworks_history` `LIKE` block (history inherits them automatically). The full `up()` SQL becomes:

```php
DB::unprepared(<<<'SQL'
    CREATE TABLE roadworks (
        id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        source         varchar(255) NOT NULL,
        source_id      varchar(255) NOT NULL,
        kind           varchar(255),
        severity       varchar(255),
        status         varchar(255),
        hindrance      varchar(255),
        activity_type  varchar(255),
        published      boolean,
        road_authority varchar(255),
        start_date     timestamptz,
        end_date       timestamptz,
        coordinates    geometry(Geometry, 4326),
        feature        jsonb NOT NULL,
        first_seen_at  timestamptz,
        last_seen_at   timestamptz,
        sys_period     tstzrange NOT NULL DEFAULT tstzrange(current_timestamp, null),
        CONSTRAINT roadworks_source_source_id_unique UNIQUE (source, source_id)
    );

    CREATE INDEX roadworks_coordinates_gist ON roadworks USING gist (coordinates);
    CREATE INDEX roadworks_feature_gin      ON roadworks USING gin (feature);
    CREATE INDEX roadworks_status_index     ON roadworks (status);
    CREATE INDEX roadworks_kind_index       ON roadworks (kind);
    CREATE INDEX roadworks_severity_index   ON roadworks (severity);
    CREATE INDEX roadworks_dates_index      ON roadworks (start_date, end_date);
    CREATE INDEX roadworks_last_seen_index  ON roadworks (last_seen_at);

    CREATE TABLE roadworks_history (
        history_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        LIKE roadworks
    );

    CREATE TRIGGER roadworks_history_trigger
        BEFORE INSERT OR UPDATE OR DELETE ON roadworks
        FOR EACH ROW
        EXECUTE PROCEDURE versioning('sys_period', 'roadworks_history', 'true', 'true');
    SQL);
```

Leave `down()` unchanged.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail artisan test --filter=RoadworksSchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_18_060100_create_roadworks_table.php tests/Feature/Datex/RoadworksSchemaTest.php
git commit -m "feat(roadworks): add DATEX promoted columns to schema"
```

---

## Task 2: Shared RoadworkUpserter

**Files:**
- Create: `app/Roadworks/RoadworkUpserter.php`
- Modify: `app/Roadworks/RoadworksImporter.php`
- Test: `tests/Feature/Datex/RoadworkUpserterTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Datex/RoadworkUpserterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkUpserterTest extends TestCase
{
    use RefreshDatabase;

    public function test_insert_then_update_and_history(): void
    {
        $up = app(RoadworkUpserter::class);
        $run = CarbonImmutable::parse('2026-06-18T10:00:00Z');

        $point = ['type' => 'Point', 'coordinates' => [5.1, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => ['x' => 1]], 'restrictions' => [], 'detours' => []];

        $created = $up->upsert('DATEX', 'NDW03_1', ['kind' => 'WORK', 'severity' => 'medium'], $point, $doc, $run);
        $this->assertTrue($created);

        $row = DB::selectOne("SELECT kind, severity, ST_AsGeoJSON(coordinates) AS g, first_seen_at, last_seen_at FROM roadworks WHERE source='DATEX' AND source_id='NDW03_1'");
        $this->assertSame('WORK', $row->kind);
        $this->assertStringContainsString('5.1', $row->g);

        // second import: changed severity, later run
        $run2 = CarbonImmutable::parse('2026-06-18T11:00:00Z');
        $created2 = $up->upsert('DATEX', 'NDW03_1', ['kind' => 'WORK', 'severity' => 'high'], $point, $doc, $run2);
        $this->assertFalse($created2); // updated, not inserted

        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source='DATEX'"));
        $this->assertSame('high', DB::scalar("SELECT severity FROM roadworks WHERE source_id='NDW03_1'"));
        // first_seen_at preserved, last_seen_at advanced
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source_id='NDW03_1' AND first_seen_at < last_seen_at"));
        // history captured the previous version
        $this->assertGreaterThanOrEqual(1, (int) DB::scalar("SELECT count(*) FROM roadworks_history WHERE source_id='NDW03_1'"));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=RoadworkUpserterTest`
Expected: FAIL (`App\Roadworks\RoadworkUpserter` not found).

- [ ] **Step 3: Create the upserter**

Create `app/Roadworks/RoadworkUpserter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Roadworks;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Single upsert path for roadworks rows, shared by every import source.
 * Upserts on (source, source_id); the temporal trigger records history.
 */
final class RoadworkUpserter
{
    private const PROMOTED = [
        'kind', 'severity', 'status', 'hindrance', 'activity_type',
        'published', 'road_authority', 'start_date', 'end_date',
    ];

    /**
     * @param  array<string, mixed>  $promoted  subset of self::PROMOTED
     * @param  array<string, mixed>|null  $point  GeoJSON Point, or null
     * @param  array<string, mixed>  $document  the feature jsonb document
     *
     * @return bool true if a new row was inserted, false if an existing row was updated
     */
    public function upsert(string $source, string $sourceId, array $promoted, ?array $point, array $document, DateTimeInterface $seenAt): bool
    {
        $vals = [];
        foreach (self::PROMOTED as $c) {
            $vals[$c] = $promoted[$c] ?? null;
        }
        $seen = $seenAt->format(DateTimeInterface::ATOM);

        $pointExpr = $point === null ? 'NULL' : 'ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)';

        $bindings = [
            $source, $sourceId,
            $vals['kind'], $vals['severity'], $vals['status'], $vals['hindrance'],
            $vals['activity_type'],
            $vals['published'] === null ? null : ($vals['published'] ? 'true' : 'false'),
            $vals['road_authority'], $vals['start_date'], $vals['end_date'],
        ];
        if ($point !== null) {
            $bindings[] = json_encode($point, JSON_THROW_ON_ERROR);
        }
        $bindings[] = json_encode($document, JSON_THROW_ON_ERROR);
        $bindings[] = $seen; // first_seen_at
        $bindings[] = $seen; // last_seen_at

        $row = DB::selectOne(
            <<<SQL
                INSERT INTO roadworks
                    (source, source_id, kind, severity, status, hindrance, activity_type,
                     published, road_authority, start_date, end_date,
                     coordinates, feature, first_seen_at, last_seen_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?::boolean, ?, ?::timestamptz, ?::timestamptz,
                     {$pointExpr}, ?::jsonb, ?::timestamptz, ?::timestamptz)
                ON CONFLICT (source, source_id) DO UPDATE SET
                    kind=EXCLUDED.kind, severity=EXCLUDED.severity, status=EXCLUDED.status,
                    hindrance=EXCLUDED.hindrance, activity_type=EXCLUDED.activity_type,
                    published=EXCLUDED.published, road_authority=EXCLUDED.road_authority,
                    start_date=EXCLUDED.start_date, end_date=EXCLUDED.end_date,
                    coordinates=EXCLUDED.coordinates, feature=EXCLUDED.feature,
                    last_seen_at=EXCLUDED.last_seen_at
                RETURNING (xmax = 0) AS inserted
                SQL,
            $bindings,
        );

        return (bool) $row->inserted;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/sail artisan test --filter=RoadworkUpserterTest`
Expected: PASS.

- [ ] **Step 5: Refactor RoadworksImporter to use the upserter**

In `app/Roadworks/RoadworksImporter.php`, inject the upserter and replace the inline `upsert()` SQL. Constructor + `import()` call site:

```php
public function __construct(private readonly RoadworkUpserter $upserter)
{
}
```

Replace the body of the existing private `upsert(array $group): bool` with a delegation (keep the grouping/`FeatureProperties` logic above it unchanged):

```php
private function upsert(array $group): bool
{
    $situation = $group['situation'];
    $properties = $group['properties'];

    $point = $situation->geometry; // already GeoJSON Point|null from Melvin Feature
    $document = [
        'situation' => $situation->toArray(),
        'restrictions' => array_map(static fn ($f) => $f->toArray(), $group['restrictions']),
        'detours' => array_map(static fn ($f) => $f->toArray(), $group['detours']),
    ];

    return $this->upserter->upsert(
        'MELVIN',
        $properties->situationId ?? (string) $situation->id,
        [
            'status' => $properties->status,
            'activity_type' => $properties->activityType,
            'published' => $properties->published,
        ],
        $point,
        $document,
        now(),
    );
}
```

- [ ] **Step 6: Run the suite to confirm no regressions**

Run: `./vendor/bin/sail artisan test --filter="RoadworkUpserterTest|RoadworksSchemaTest"`
Expected: PASS. Also `./vendor/bin/sail php vendor/bin/phpstan analyse app/Roadworks --no-progress` (if phpstan configured): no new errors.

- [ ] **Step 7: Commit**

```bash
git add app/Roadworks/RoadworkUpserter.php app/Roadworks/RoadworksImporter.php tests/Feature/Datex/RoadworkUpserterTest.php
git commit -m "feat(roadworks): extract shared RoadworkUpserter"
```

---

## Task 3: Datex situation mapper

**Files:**
- Create: `app/Roadworks/Datex/MappedRoadwork.php`
- Create: `app/Roadworks/Datex/DatexSituationMapper.php`
- Modify: `app/Roadworks/Data/RoadworkDocument.php`
- Test: `tests/Unit/Datex/DatexSituationMapperTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Datex/DatexSituationMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Datex;

use App\Roadworks\Datex\DatexSituationMapper;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

class DatexSituationMapperTest extends TestCase
{
    private function situation(string $inner): SimpleXMLElement
    {
        $xml = <<<XML
        <sit:situation xmlns:sit="http://datex2.eu/schema/3/situation"
                       xmlns:com="http://datex2.eu/schema/3/common"
                       xmlns:loc="http://datex2.eu/schema/3/locationReferencing"
                       xmlns:nle="http://datex2.eu/schema/3/nlExtensions"
                       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" id="NDW03_42">
          <sit:overallSeverity>medium</sit:overallSeverity>
          {$inner}
        </sit:situation>
        XML;

        return new SimpleXMLElement($xml);
    }

    public function test_maps_maintenance_with_rerouting(): void
    {
        $inner = <<<XML
        <sit:situationRecord xsi:type="sit:MaintenanceWorks" id="r1">
          <sit:probabilityOfOccurrence>certain</sit:probabilityOfOccurrence>
          <sit:source><com:sourceName><com:values><com:value lang="nl">RWS Zuid</com:value></com:values></com:sourceName></sit:source>
          <sit:validity><com:validityTimeSpecification>
            <com:overallStartTime>2026-07-20T03:00:00Z</com:overallStartTime>
            <com:overallEndTime>2026-07-22T03:00:00Z</com:overallEndTime>
          </com:validityTimeSpecification></sit:validity>
          <sit:cause><sit:causeType>roadMaintenance</sit:causeType></sit:cause>
          <sit:locationReference xsi:type="loc:PointLocation"><loc:pointByCoordinates><loc:pointCoordinates>
            <loc:latitude>51.39906</loc:latitude><loc:longitude>6.127533</loc:longitude>
          </loc:pointCoordinates></loc:pointByCoordinates></sit:locationReference>
          <nle:roadworkStatus>running</nle:roadworkStatus>
          <nle:roadworkHindranceClass>hindranceClass1</nle:roadworkHindranceClass>
          <com:informationStatus>real</com:informationStatus>
        </sit:situationRecord>
        <sit:situationRecord xsi:type="sit:ReroutingManagement" id="r2">
          <sit:alternativeRoute><loc:locationContainedInItinerary><loc:location xsi:type="loc:LinearLocation">
            <loc:gmlLineString srsName="WGS 84"><loc:posList>51.399 6.127 51.398 6.128</loc:posList></loc:gmlLineString>
          </loc:location></loc:locationContainedInItinerary></sit:alternativeRoute>
          <com:informationStatus>real</com:informationStatus>
        </sit:situationRecord>
        XML;

        $m = (new DatexSituationMapper())->map($this->situation($inner));

        $this->assertNotNull($m);
        $this->assertSame('NDW03_42', $m->sourceId);
        $this->assertSame('WORK', $m->kind);
        $this->assertSame('medium', $m->severity);
        $this->assertSame('running', $m->status);
        $this->assertSame('hindranceClass1', $m->hindrance);
        $this->assertSame('RWS Zuid', $m->roadAuthority);
        $this->assertSame('2026-07-20T03:00:00Z', $m->startDate);
        $this->assertSame('Point', $m->point['type']);
        // lon,lat order
        $this->assertEqualsWithDelta(6.127533, $m->point['coordinates'][0], 1e-6);
        $this->assertEqualsWithDelta(51.39906, $m->point['coordinates'][1], 1e-6);

        // detour line present, lon/lat swapped
        $det = $m->document['detours'][0];
        $this->assertSame('LineString', $det['geometry']['type']);
        $this->assertEqualsWithDelta(6.127, $det['geometry']['coordinates'][0][0], 1e-6);
        $this->assertEqualsWithDelta(51.399, $det['geometry']['coordinates'][0][1], 1e-6);
    }

    public function test_skips_non_real_records(): void
    {
        $inner = <<<XML
        <sit:situationRecord xsi:type="sit:MaintenanceWorks" id="r1">
          <com:informationStatus>test</com:informationStatus>
        </sit:situationRecord>
        XML;

        $this->assertNull((new DatexSituationMapper())->map($this->situation($inner)));
    }

    public function test_public_event_maps_to_event_kind(): void
    {
        $inner = <<<XML
        <sit:situationRecord xsi:type="sit:PublicEvent" id="r1">
          <com:informationStatus>real</com:informationStatus>
          <sit:cause><sit:causeType>publicEvent</sit:causeType></sit:cause>
        </sit:situationRecord>
        XML;

        $m = (new DatexSituationMapper())->map($this->situation($inner));
        $this->assertSame('EVENT', $m->kind);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=DatexSituationMapperTest`
Expected: FAIL (`App\Roadworks\Datex\DatexSituationMapper` not found).

- [ ] **Step 3: Add `attachments` to RoadworkDocument**

In `app/Roadworks/Data/RoadworkDocument.php`, add a property (keep existing ones):

```php
public function __construct(
    public ?Feature $situation = null,
    /** @var list<Feature> */
    #[\Spatie\LaravelData\Attributes\DataCollectionOf(Feature::class)]
    public array $restrictions = [],
    /** @var list<Feature> */
    #[\Spatie\LaravelData\Attributes\DataCollectionOf(Feature::class)]
    public array $detours = [],
    /** @var list<array{url: string, description: ?string}> */
    public array $attachments = [],
) {
}
```

- [ ] **Step 4: Create the MappedRoadwork value object**

Create `app/Roadworks/Datex/MappedRoadwork.php`:

```php
<?php

declare(strict_types=1);

namespace App\Roadworks\Datex;

/**
 * Output of {@see DatexSituationMapper}: promoted scalars + the GeoJSON point
 * + the {situation, restrictions, detours, attachments} document for jsonb.
 */
final readonly class MappedRoadwork
{
    public function __construct(
        public string $sourceId,
        public ?string $kind,
        public ?string $severity,
        public ?string $status,
        public ?string $hindrance,
        public ?string $roadAuthority,
        public ?string $startDate,
        public ?string $endDate,
        /** @var array<string, mixed>|null GeoJSON Point */
        public ?array $point,
        /** @var array<string, mixed> */
        public array $document,
    ) {
    }
}
```

- [ ] **Step 5: Create the mapper**

Create `app/Roadworks/Datex/DatexSituationMapper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Roadworks\Datex;

use SimpleXMLElement;

final class DatexSituationMapper
{
    private const NS = [
        'sit' => 'http://datex2.eu/schema/3/situation',
        'com' => 'http://datex2.eu/schema/3/common',
        'loc' => 'http://datex2.eu/schema/3/locationReferencing',
        'nle' => 'http://datex2.eu/schema/3/nlExtensions',
        'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
    ];

    private const SITUATION_TYPES = ['MaintenanceWorks', 'ConstructionWorks', 'PublicEvent'];
    private const RESTRICTION_TYPES = ['RoadOrCarriagewayOrLaneManagement', 'SpeedManagement'];
    private const DETOUR_TYPES = ['ReroutingManagement'];

    public function map(SimpleXMLElement $situation): ?MappedRoadwork
    {
        $this->ns($situation);
        $records = $situation->xpath('sit:situationRecord') ?: [];

        $primary = null;
        $restrictions = [];
        $detours = [];
        $real = false;

        foreach ($records as $rec) {
            $this->ns($rec);
            if ($this->text($rec, './/com:informationStatus') === 'real') {
                $real = true;
            }
            $type = $this->recordType($rec);
            if (in_array($type, self::SITUATION_TYPES, true)) {
                $primary ??= $rec;
            } elseif (in_array($type, self::RESTRICTION_TYPES, true)) {
                $restrictions[] = $this->feature($rec, $this->lineString($rec));
            } elseif (in_array($type, self::DETOUR_TYPES, true)) {
                $detours[] = $this->feature($rec, $this->lineString($rec));
            }
        }

        if (! $real || $primary === null) {
            return null;
        }

        $point = $this->point($primary);
        $kind = $this->recordType($primary) === 'PublicEvent' ? 'EVENT' : 'WORK';

        $document = [
            'situation' => $this->feature($primary, $point),
            'restrictions' => $restrictions,
            'detours' => $detours,
            'attachments' => $this->attachments($situation),
        ];

        return new MappedRoadwork(
            sourceId: (string) $situation['id'],
            kind: $kind,
            severity: $this->text($situation, 'sit:overallSeverity'),
            status: $this->text($primary, './/nle:roadworkStatus') ?? $this->text($primary, './/nle:roadworkPlanningStatus'),
            hindrance: $this->text($primary, './/nle:roadworkHindranceClass'),
            roadAuthority: $this->text($primary, './/sit:source//com:value'),
            startDate: $this->text($primary, './/com:overallStartTime'),
            endDate: $this->text($primary, './/com:overallEndTime'),
            point: $point,
            document: $document,
        );
    }

    /** @param array<string,mixed>|null $geometry */
    private function feature(SimpleXMLElement $rec, ?array $geometry): array
    {
        return [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => [
                'recordType' => $this->recordType($rec),
                'causeType' => $this->text($rec, './/sit:causeType'),
                'causeDescription' => $this->text($rec, './/sit:causeDescription//com:value'),
                'reroutingManagementType' => $this->text($rec, './/sit:reroutingManagementType'),
                'numberOfOperationalLanes' => $this->text($rec, './/sit:numberOfOperationalLanes'),
                'temporarySpeedLimit' => $this->text($rec, './/sit:temporarySpeedLimit'),
            ],
        ];
    }

    private function point(SimpleXMLElement $rec): ?array
    {
        $lat = $this->text($rec, './/loc:pointByCoordinates/loc:pointCoordinates/loc:latitude');
        $lon = $this->text($rec, './/loc:pointByCoordinates/loc:pointCoordinates/loc:longitude');
        if ($lat === null || $lon === null) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [(float) $lon, (float) $lat]];
    }

    private function lineString(SimpleXMLElement $rec): ?array
    {
        $this->ns($rec);
        $lists = $rec->xpath('.//loc:gmlLineString/loc:posList') ?: [];
        foreach ($lists as $list) {
            $nums = preg_split('/\s+/', trim((string) $list)) ?: [];
            $coords = [];
            for ($i = 0; $i + 1 < count($nums); $i += 2) {
                $coords[] = [(float) $nums[$i + 1], (float) $nums[$i]]; // swap lat lon -> lon lat
            }
            if (count($coords) >= 2) {
                return ['type' => 'LineString', 'coordinates' => $coords];
            }
        }

        return null;
    }

    /** @return list<array{url: string, description: ?string}> */
    private function attachments(SimpleXMLElement $situation): array
    {
        $this->ns($situation);
        $out = [];
        foreach ($situation->xpath('.//com:urlLinkAddress') ?: [] as $a) {
            $out[] = ['url' => (string) $a, 'description' => null];
        }

        return $out;
    }

    private function recordType(SimpleXMLElement $rec): string
    {
        $type = (string) ($rec->attributes(self::NS['xsi'])['type'] ?? '');

        return str_contains($type, ':') ? explode(':', $type)[1] : $type;
    }

    private function text(SimpleXMLElement $el, string $xpath): ?string
    {
        $this->ns($el);
        $hit = $el->xpath($xpath);

        return $hit ? trim((string) $hit[0]) : null;
    }

    private function ns(SimpleXMLElement $el): void
    {
        foreach (self::NS as $prefix => $uri) {
            $el->registerXPathNamespace($prefix, $uri);
        }
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter=DatexSituationMapperTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Roadworks/Datex/MappedRoadwork.php app/Roadworks/Datex/DatexSituationMapper.php app/Roadworks/Data/RoadworkDocument.php tests/Unit/Datex/DatexSituationMapperTest.php
git commit -m "feat(datex): situation mapper -> normalized roadwork document"
```

---

## Task 4: Streaming feed reader

**Files:**
- Create: `app/Roadworks/Datex/DatexFeedReader.php`
- Create: `tests/Fixtures/datex/sample.xml`
- Test: `tests/Feature/Datex/DatexFeedReaderTest.php`

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/datex/sample.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<mc:messageContainer xmlns:mc="http://datex2.eu/schema/3/messageContainer"
                     xmlns:sit="http://datex2.eu/schema/3/situation"
                     xmlns:com="http://datex2.eu/schema/3/common"
                     xmlns:loc="http://datex2.eu/schema/3/locationReferencing"
                     xmlns:nle="http://datex2.eu/schema/3/nlExtensions"
                     xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <mc:payload xsi:type="sit:SituationPublication">
    <sit:situation id="NDW03_100">
      <sit:overallSeverity>medium</sit:overallSeverity>
      <sit:situationRecord xsi:type="sit:MaintenanceWorks" id="r1">
        <sit:source><com:sourceName><com:values><com:value lang="nl">RWS Zuid</com:value></com:values></com:sourceName></sit:source>
        <sit:validity><com:validityTimeSpecification>
          <com:overallStartTime>2026-07-20T03:00:00Z</com:overallStartTime>
          <com:overallEndTime>2026-07-22T03:00:00Z</com:overallEndTime>
        </com:validityTimeSpecification></sit:validity>
        <sit:cause><sit:causeType>roadMaintenance</sit:causeType></sit:cause>
        <sit:locationReference xsi:type="loc:PointLocation"><loc:pointByCoordinates><loc:pointCoordinates>
          <loc:latitude>51.39906</loc:latitude><loc:longitude>6.127533</loc:longitude>
        </loc:pointCoordinates></loc:pointByCoordinates></sit:locationReference>
        <nle:roadworkStatus>running</nle:roadworkStatus>
        <nle:roadworkHindranceClass>hindranceClass1</nle:roadworkHindranceClass>
        <com:informationStatus>real</com:informationStatus>
      </sit:situationRecord>
      <sit:situationRecord xsi:type="sit:ReroutingManagement" id="r2">
        <sit:alternativeRoute><loc:locationContainedInItinerary><loc:location xsi:type="loc:LinearLocation">
          <loc:gmlLineString srsName="WGS 84"><loc:posList>51.399 6.127 51.398 6.128 51.397 6.129</loc:posList></loc:gmlLineString>
        </loc:location></loc:locationContainedInItinerary></sit:alternativeRoute>
        <com:informationStatus>real</com:informationStatus>
      </sit:situationRecord>
    </sit:situation>
    <sit:situation id="NDW03_200">
      <sit:overallSeverity>low</sit:overallSeverity>
      <sit:situationRecord xsi:type="sit:PublicEvent" id="r1">
        <sit:cause><sit:causeType>publicEvent</sit:causeType></sit:cause>
        <sit:locationReference xsi:type="loc:PointLocation"><loc:pointByCoordinates><loc:pointCoordinates>
          <loc:latitude>52.0</loc:latitude><loc:longitude>5.0</loc:longitude>
        </loc:pointCoordinates></loc:pointByCoordinates></sit:locationReference>
        <com:urlLinkAddress>https://example.test/attachment/abc</com:urlLinkAddress>
        <com:informationStatus>real</com:informationStatus>
      </sit:situationRecord>
    </sit:situation>
    <sit:situation id="NDW03_300">
      <sit:situationRecord xsi:type="sit:MaintenanceWorks" id="r1">
        <com:informationStatus>test</com:informationStatus>
      </sit:situationRecord>
    </sit:situation>
  </mc:payload>
</mc:messageContainer>
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Datex/DatexFeedReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use App\Roadworks\Datex\DatexFeedReader;
use PHPUnit\Framework\TestCase;

class DatexFeedReaderTest extends TestCase
{
    public function test_yields_each_situation_with_namespaces(): void
    {
        $ids = [];
        foreach ((new DatexFeedReader())->read(base_path('tests/Fixtures/datex/sample.xml')) as $sit) {
            $sit->registerXPathNamespace('sit', 'http://datex2.eu/schema/3/situation');
            $ids[] = (string) $sit['id'];
        }

        $this->assertSame(['NDW03_100', 'NDW03_200', 'NDW03_300'], $ids);
    }

    public function test_reads_gzipped_file(): void
    {
        $gz = tempnam(sys_get_temp_dir(), 'datex').'.gz';
        file_put_contents("compress.zlib://{$gz}", file_get_contents(base_path('tests/Fixtures/datex/sample.xml')));

        $count = iterator_count((new DatexFeedReader())->read($gz));
        @unlink($gz);

        $this->assertSame(3, $count);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=DatexFeedReaderTest`
Expected: FAIL (`App\Roadworks\Datex\DatexFeedReader` not found).

- [ ] **Step 4: Create the reader**

Create `app/Roadworks/Datex/DatexFeedReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Roadworks\Datex;

use DOMDocument;
use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

/**
 * Streams a DATEX II SituationPublication, yielding one <sit:situation> at a
 * time. Handles plain `.xml`, gzipped `.xml.gz`, and remote URLs (downloaded to
 * a temp `.gz` first, then decompressed on the fly — the 207 MB XML is never
 * fully materialised).
 */
final class DatexFeedReader
{
    /** @return Generator<int, SimpleXMLElement> */
    public function read(string $urlOrPath): Generator
    {
        [$uri, $tmp] = $this->resolve($urlOrPath);

        $reader = new XMLReader();
        if (! $reader->open($uri)) {
            throw new RuntimeException("Unable to open DATEX feed: {$urlOrPath}");
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'situation') {
                    $doc = new DOMDocument();
                    $node = $reader->expand($doc);
                    if ($node !== false) {
                        $doc->appendChild($node);
                        yield simplexml_import_dom($node);
                    }
                    $reader->next();
                }
            }
        } finally {
            $reader->close();
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @return array{0: string, 1: ?string} [uri to open, temp file to clean up or null]
     */
    private function resolve(string $urlOrPath): array
    {
        $isUrl = str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://');

        if ($isUrl) {
            $tmp = tempnam(sys_get_temp_dir(), 'datex').'.gz';
            $in = fopen($urlOrPath, 'rb');
            $out = fopen($tmp, 'wb');
            if ($in === false || $out === false) {
                throw new RuntimeException("Unable to download DATEX feed: {$urlOrPath}");
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            return ["compress.zlib://{$tmp}", $tmp];
        }

        $uri = str_ends_with($urlOrPath, '.gz') ? "compress.zlib://{$urlOrPath}" : $urlOrPath;

        return [$uri, null];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter=DatexFeedReaderTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Roadworks/Datex/DatexFeedReader.php tests/Fixtures/datex/sample.xml tests/Feature/Datex/DatexFeedReaderTest.php
git commit -m "feat(datex): streaming feed reader"
```

---

## Task 5: Import command (end-to-end)

**Files:**
- Create: `app/Console/Commands/ImportDatexRoadworks.php`
- Test: `tests/Feature/Datex/ImportDatexRoadworksTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Datex/ImportDatexRoadworksTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportDatexRoadworksTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): string
    {
        return base_path('tests/Fixtures/datex/sample.xml');
    }

    public function test_imports_real_situations_and_skips_test_records(): void
    {
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();

        // NDW03_300 is informationStatus=test -> skipped
        $this->assertSame(2, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source='DATEX'"));

        $work = DB::selectOne("SELECT kind, severity, status, road_authority, ST_AsGeoJSON(coordinates) AS g, feature FROM roadworks WHERE source_id='NDW03_100'");
        $this->assertSame('WORK', $work->kind);
        $this->assertSame('medium', $work->severity);
        $this->assertSame('running', $work->status);
        $this->assertSame('RWS Zuid', $work->road_authority);
        $this->assertStringContainsString('6.127533', $work->g);

        // detour LineString stored in jsonb, lon/lat order
        $feature = json_decode($work->feature, true);
        $line = $feature['detours'][0]['geometry'];
        $this->assertSame('LineString', $line['type']);
        $this->assertEqualsWithDelta(6.127, $line['coordinates'][0][0], 1e-6);

        $event = DB::selectOne("SELECT kind, feature FROM roadworks WHERE source_id='NDW03_200'");
        $this->assertSame('EVENT', $event->kind);
        $this->assertSame('https://example.test/attachment/abc', json_decode($event->feature, true)['attachments'][0]['url']);
    }

    public function test_reimport_is_idempotent_and_writes_history_on_change(): void
    {
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();

        // no duplicates
        $this->assertSame(2, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source='DATEX'"));
        // identical re-import -> ignore_unchanged_values means no history rows
        $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM roadworks_history WHERE source_id='NDW03_100'"));

        // change a value, re-import -> one history row
        DB::update("UPDATE roadworks SET severity='high' WHERE source_id='NDW03_100'");
        $this->artisan('roadworks:import:datex', ['file' => $this->fixture()])->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, (int) DB::scalar("SELECT count(*) FROM roadworks_history WHERE source_id='NDW03_100'"));
        $this->assertSame('medium', DB::scalar("SELECT severity FROM roadworks WHERE source_id='NDW03_100'"));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/sail artisan test --filter=ImportDatexRoadworksTest`
Expected: FAIL (command `roadworks:import:datex` not found).

- [ ] **Step 3: Create the command**

Create `app/Console/Commands/ImportDatexRoadworks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Roadworks\Datex\DatexFeedReader;
use App\Roadworks\Datex\DatexSituationMapper;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('roadworks:import:datex {file? : Local .xml/.xml.gz path; downloads the live feed if omitted}')]
#[Description('Import roadworks & events from the NDW open DATEX planningsfeed')]
class ImportDatexRoadworks extends Command
{
    private const FEED_URL = 'https://opendata.ndw.nu/planningsfeed_wegwerkzaamheden_en_evenementen.xml.gz';

    public function handle(DatexFeedReader $reader, DatexSituationMapper $mapper, RoadworkUpserter $upserter): int
    {
        $source = $this->argument('file') ?? self::FEED_URL;
        $runAt = CarbonImmutable::now();
        $created = $updated = $skipped = 0;

        try {
            DB::beginTransaction();
            $n = 0;
            foreach ($reader->read($source) as $situation) {
                try {
                    $mapped = $mapper->map($situation);
                    if ($mapped === null) {
                        $skipped++;

                        continue;
                    }
                    $isNew = $upserter->upsert(
                        'DATEX',
                        $mapped->sourceId,
                        [
                            'kind' => $mapped->kind,
                            'severity' => $mapped->severity,
                            'status' => $mapped->status,
                            'hindrance' => $mapped->hindrance,
                            'road_authority' => $mapped->roadAuthority,
                            'start_date' => $mapped->startDate,
                            'end_date' => $mapped->endDate,
                        ],
                        $mapped->point,
                        $mapped->document,
                        $runAt,
                    );
                    $isNew ? $created++ : $updated++;
                } catch (Throwable $e) {
                    $skipped++;
                    $this->warn("Skipped a situation: {$e->getMessage()}");
                }

                if (++$n % 500 === 0) {
                    DB::commit();
                    DB::beginTransaction();
                }
            }
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error("Import failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(['Created', 'Updated', 'Skipped'], [[$created, $updated, $skipped]]);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/sail artisan test --filter=ImportDatexRoadworksTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full Datex suite**

Run: `./vendor/bin/sail artisan test --filter=Datex`
Expected: PASS (all tasks' tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/ImportDatexRoadworks.php tests/Feature/Datex/ImportDatexRoadworksTest.php
git commit -m "feat(datex): roadworks:import:datex command"
```

---

## Task 6: Smoke-test against the live feed (manual)

**Files:** none (manual verification).

- [ ] **Step 1: Run against a saved real snapshot**

```bash
curl -fsSL https://opendata.ndw.nu/planningsfeed_wegwerkzaamheden_en_evenementen.xml.gz -o storage/app/datex.xml.gz
./vendor/bin/sail artisan roadworks:import:datex storage/app/datex.xml.gz
```
Expected: summary table with Created in the thousands, low Skipped, no fatal error; memory stays flat (streaming).

- [ ] **Step 2: Spot-check a known situation**

```bash
./vendor/bin/sail artisan tinker --execute="dump(App\Models\Roadwork::where('source','DATEX')->where('source_id','like','%1065233%')->first()?->only(['source_id','kind','status','road_authority']));"
```
Expected: the A27 Vianen maintenance row with `kind=WORK`.

- [ ] **Step 3: Confirm idempotency on the live data**

```bash
./vendor/bin/sail artisan roadworks:import:datex storage/app/datex.xml.gz
```
Expected: second run shows mostly Updated (≈0 Created), `roadworks_history` grows only by genuinely-changed rows.

---

## Self-Review

- **Spec coverage:** feed reader (Task 4), mapper + record bucketing + geometry swap + attachments (Task 3), normalized document/`RoadworkDocument` (Task 3), shared upserter + Melvin refactor (Task 2), promoted columns via edited create-table (Task 1), command download/file + batching + skip-non-real + per-situation error isolation (Task 5), pgsql fixture tests incl. idempotency + history (Tasks 2,4,5), never-delete `first_seen_at`/`last_seen_at` (Tasks 1,2). Live smoke test (Task 6). All spec sections covered.
- **Placeholder scan:** none — every step has complete code/commands.
- **Type consistency:** `MappedRoadwork` fields used identically in mapper (Task 3) and command (Task 5); `RoadworkUpserter::upsert(string,string,array,?array,array,DateTimeInterface): bool` signature consistent across Tasks 2 and 5; promoted-column names match the migration (Task 1).
