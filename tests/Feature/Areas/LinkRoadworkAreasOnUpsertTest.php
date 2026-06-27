<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Events\RoadworkSaved;
use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class LinkRoadworkAreasOnUpsertTest extends TestCase
{
    use RefreshDatabase;

    public function test_upserting_a_roadwork_dispatches_roadwork_saved(): void
    {
        Event::fake([RoadworkSaved::class]);

        $this->upsert(['type' => 'Point', 'coordinates' => [0.5, 0.5]]);

        Event::assertDispatched(RoadworkSaved::class, fn (RoadworkSaved $e): bool => $e->roadworkId > 0);
    }

    public function test_the_listener_links_the_roadwork_to_its_areas(): void
    {
        $this->area('landsdelen', 'LD01', 'POLYGON((0 0,10 0,10 10,0 10,0 0))');
        $this->area('provincies', 'PV20', 'POLYGON((0 0,5 0,5 10,0 10,0 0))');
        $this->area('gemeenten', 'GM0001', 'POLYGON((0 0,5 0,5 5,0 5,0 0))');
        $this->area('wijken', 'WK000100', 'POLYGON((0 0,2 0,2 2,0 2,0 0))');
        $this->area('buurten', 'BU00010000', 'POLYGON((0 0,1 0,1 1,0 1,0 0))');

        $this->upsert(['type' => 'Point', 'coordinates' => [0.5, 0.5]]);

        $roadwork = Roadwork::firstOrFail();
        $this->assertSame('BU00010000', $roadwork->buurten->sole()->code);
        $this->assertSame('GM0001', $roadwork->gemeenten->sole()->code);
        $this->assertSame('LD01', $roadwork->landsdelen->sole()->code);
    }

    /**
     * @param  array<string, mixed>  $geometry
     */
    private function upsert(array $geometry): void
    {
        app(RoadworkUpserter::class)->upsert(
            source: 'test',
            sourceId: uniqid(),
            promoted: ['status' => 'active'],
            point: $geometry,
            document: ['situation' => ['geometry' => $geometry]],
            seenAt: CarbonImmutable::now(),
        );
    }

    private function area(string $table, string $code, string $wkt): void
    {
        DB::statement(
            "INSERT INTO {$table} (code, name, year, geometry)
             VALUES (?, ?, 2024, ST_Multi(ST_GeomFromText(?, 4326)))",
            [$code, $code, $wkt],
        );
    }
}
