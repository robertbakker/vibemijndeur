<?php

declare(strict_types=1);

namespace Tests\Feature\Datex;

use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkUpserterTest extends TestCase
{
    // The temporal history trigger's xmin check needs INSERT and UPDATE in
    // separate transactions; DatabaseMigrations avoids RefreshDatabase's
    // single wrapping transaction so the production 4-arg trigger behaves correctly.
    use DatabaseMigrations;

    public function test_insert_then_update_and_history(): void
    {
        $up = app(RoadworkUpserter::class);
        $run = CarbonImmutable::parse('2026-06-18T10:00:00Z');

        $point = ['type' => 'Point', 'coordinates' => [5.1, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => ['x' => 1]], 'restrictions' => [], 'detours' => []];

        $created = $up->upsert('DATEX', 'NDW03_1', ['kind' => 'WORK', 'severity' => 'medium'], $point, $doc, $run);
        $this->assertTrue($created);

        $row = DB::selectOne("SELECT kind, severity, ST_AsGeoJSON(coordinates) AS g FROM roadworks WHERE source='DATEX' AND source_id='NDW03_1'");
        $this->assertSame('WORK', $row->kind);
        $this->assertStringContainsString('5.1', $row->g);

        $run2 = CarbonImmutable::parse('2026-06-18T11:00:00Z');
        $created2 = $up->upsert('DATEX', 'NDW03_1', ['kind' => 'WORK', 'severity' => 'high'], $point, $doc, $run2);
        $this->assertFalse($created2);

        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM roadworks WHERE source='DATEX'"));
        $this->assertSame('high', DB::scalar("SELECT severity FROM roadworks WHERE source_id='NDW03_1'"));
        // feed-presence tracked in the non-versioned sibling table
        $this->assertSame(1, (int) DB::scalar("SELECT count(*) FROM roadwork_seen WHERE source_id='NDW03_1' AND first_seen_at < last_seen_at"));
        $this->assertGreaterThanOrEqual(1, (int) DB::scalar("SELECT count(*) FROM roadworks_history WHERE source_id='NDW03_1'"));
    }
}
