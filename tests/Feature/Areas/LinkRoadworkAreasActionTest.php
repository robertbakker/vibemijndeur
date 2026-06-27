<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Actions\LinkRoadworkBuurten;
use App\Actions\LinkRoadworkGemeenten;
use App\Actions\LinkRoadworkLandsdelen;
use App\Actions\LinkRoadworkProvincies;
use App\Actions\LinkRoadworkWijken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinkRoadworkAreasActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nested squares: landsdeel(0..10) ⊃ provincie(0..5 wide) ⊃ gemeente(0..5)
        // ⊃ wijk(0..2) ⊃ two adjacent buurten B1(0..1) and B2(1..2).
        $this->area('landsdelen', 'LD01', 'POLYGON((0 0,10 0,10 10,0 10,0 0))');
        $this->area('provincies', 'PV20', 'POLYGON((0 0,5 0,5 10,0 10,0 0))');
        $this->area('gemeenten', 'GM0001', 'POLYGON((0 0,5 0,5 5,0 5,0 0))');
        $this->area('wijken', 'WK000100', 'POLYGON((0 0,2 0,2 2,0 2,0 0))');
        $this->area('buurten', 'BU00010000', 'POLYGON((0 0,1 0,1 1,0 1,0 0))');
        $this->area('buurten', 'BU00010001', 'POLYGON((1 0,2 0,2 1,1 1,1 0))');
    }

    public function test_a_point_links_one_area_at_every_level(): void
    {
        $roadwork = $this->roadwork(['type' => 'Point', 'coordinates' => [0.5, 0.5]]);

        $this->linkAll($roadwork);

        $this->assertSame(['BU00010000'], $this->linkedCodes($roadwork, 'roadwork_buurt', 'buurten', 'buurt_id'));
        $this->assertSame(['WK000100'], $this->linkedCodes($roadwork, 'roadwork_wijk', 'wijken', 'wijk_id'));
        $this->assertSame(['GM0001'], $this->linkedCodes($roadwork, 'roadwork_gemeente', 'gemeenten', 'gemeente_id'));
        $this->assertSame(['PV20'], $this->linkedCodes($roadwork, 'roadwork_provincie', 'provincies', 'provincie_id'));
        $this->assertSame(['LD01'], $this->linkedCodes($roadwork, 'roadwork_landsdeel', 'landsdelen', 'landsdeel_id'));
    }

    public function test_a_line_crossing_two_buurten_links_both(): void
    {
        $roadwork = $this->roadwork(['type' => 'LineString', 'coordinates' => [[0.5, 0.5], [1.5, 0.5]]]);

        $this->linkAll($roadwork);

        $this->assertSame(['BU00010000', 'BU00010001'], $this->linkedCodes($roadwork, 'roadwork_buurt', 'buurten', 'buurt_id'));
        $this->assertSame(['WK000100'], $this->linkedCodes($roadwork, 'roadwork_wijk', 'wijken', 'wijk_id'));
        $this->assertSame(['GM0001'], $this->linkedCodes($roadwork, 'roadwork_gemeente', 'gemeenten', 'gemeente_id'));
    }

    public function test_a_roadwork_without_geometry_gets_no_links(): void
    {
        $roadwork = $this->roadwork(null);

        $this->linkAll($roadwork);

        $this->assertSame([], $this->linkedCodes($roadwork, 'roadwork_buurt', 'buurten', 'buurt_id'));
    }

    public function test_a_json_null_situation_geometry_is_ignored(): void
    {
        // Real Melvin/Datex rows can store "geometry": null inside the situation —
        // a JSON null (jsonb), not a missing key. It must not reach ST_GeomFromGeoJSON.
        $roadwork = (int) DB::selectOne(
            "INSERT INTO roadworks (source, source_id, feature)
             VALUES ('test', ?, '{\"situation\": {\"geometry\": null}}'::jsonb) RETURNING id",
            [uniqid()],
        )->id;

        $this->linkAll($roadwork);

        $this->assertSame([], $this->linkedCodes($roadwork, 'roadwork_buurt', 'buurten', 'buurt_id'));
    }

    public function test_relinking_is_idempotent_and_reflects_a_moved_geometry(): void
    {
        $roadwork = $this->roadwork(['type' => 'Point', 'coordinates' => [0.5, 0.5]]);
        $this->linkAll($roadwork);
        $this->linkAll($roadwork);

        $this->assertSame(['BU00010000'], $this->linkedCodes($roadwork, 'roadwork_buurt', 'buurten', 'buurt_id'));

        // Move it into the other buurt; relinking must replace, not accumulate.
        DB::update('UPDATE roadworks SET feature = ? WHERE id = ?', [
            json_encode(['situation' => ['geometry' => ['type' => 'Point', 'coordinates' => [1.5, 0.5]]]]),
            $roadwork,
        ]);
        $this->linkAll($roadwork);

        $this->assertSame(['BU00010001'], $this->linkedCodes($roadwork, 'roadwork_buurt', 'buurten', 'buurt_id'));
    }

    private function linkAll(int $roadwork): void
    {
        app(LinkRoadworkLandsdelen::class)($roadwork);
        app(LinkRoadworkProvincies::class)($roadwork);
        app(LinkRoadworkGemeenten::class)($roadwork);
        app(LinkRoadworkWijken::class)($roadwork);
        app(LinkRoadworkBuurten::class)($roadwork);
    }

    private function area(string $table, string $code, string $wkt): void
    {
        DB::statement(
            "INSERT INTO {$table} (code, name, year, geometry)
             VALUES (?, ?, 2024, ST_Multi(ST_GeomFromText(?, 4326)))",
            [$code, $code, $wkt],
        );
    }

    /**
     * @param  array<string, mixed>|null  $geometry
     */
    private function roadwork(?array $geometry): int
    {
        $feature = $geometry === null ? [] : ['situation' => ['geometry' => $geometry]];

        return (int) DB::selectOne(
            "INSERT INTO roadworks (source, source_id, feature) VALUES ('test', ?, ?::jsonb) RETURNING id",
            [uniqid(), json_encode($feature)],
        )->id;
    }

    /**
     * @return list<string>
     */
    private function linkedCodes(int $roadwork, string $pivot, string $areaTable, string $areaKey): array
    {
        return DB::table($pivot)
            ->join($areaTable, "{$areaTable}.id", '=', "{$pivot}.{$areaKey}")
            ->where("{$pivot}.roadwork_id", $roadwork)
            ->orderBy("{$areaTable}.code")
            ->pluck("{$areaTable}.code")
            ->all();
    }
}
