<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Wijk;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ImportCbsAreasTest extends TestCase
{
    // ogr2ogr loads staging tables over a separate DB connection, so the test
    // cannot run inside RefreshDatabase's wrapping transaction (the importer's
    // committed writes must be visible to that connection, and vice versa).
    use DatabaseMigrations;

    private function import(): void
    {
        $fixture = base_path('tests/Fixtures/cbs/areas.gpkg');

        $this->artisan('cbs:import:areas', ['file' => $fixture, '--year' => 2024])
            ->assertSuccessful();
    }

    public function test_it_imports_every_level_with_its_geometry(): void
    {
        $this->import();

        $this->assertDatabaseCount('landsdelen', 1);
        $this->assertDatabaseCount('provincies', 2);
        $this->assertDatabaseCount('gemeenten', 2);
        $this->assertDatabaseCount('wijken', 2);
        $this->assertDatabaseCount('buurten', 3);

        // Geometry survives the reprojection to 4326 as a MultiPolygon.
        $gemeente = Gemeente::where('code', 'GM0014')->withGeoJson()->first();
        $this->assertStringContainsString('MultiPolygon', (string) $gemeente->geometry_geojson);
    }

    public function test_buurt_links_to_wijk_by_code_and_gemeente_by_attribute(): void
    {
        $this->import();

        $buurt = Buurt::where('code', 'BU00140000')->firstOrFail();

        $this->assertSame('WK001400', $buurt->wijk->code);
        $this->assertSame('GM0014', $buurt->gemeente->code);
    }

    public function test_buurt_with_unknown_wijk_code_still_imports_with_null_wijk(): void
    {
        $this->import();

        $buurt = Buurt::where('code', 'BU00149900')->firstOrFail();

        $this->assertNull($buurt->wijk_id);
        $this->assertSame('GM0014', $buurt->gemeente->code);
    }

    public function test_wijk_links_to_gemeente_by_gm_code(): void
    {
        $this->import();

        $this->assertSame('GM0014', Wijk::where('code', 'WK001400')->firstOrFail()->gemeente->code);
    }

    public function test_gemeente_links_to_provincie_spatially(): void
    {
        $this->import();

        $this->assertSame('PV20', Gemeente::where('code', 'GM0014')->firstOrFail()->provincie->code);
        $this->assertSame('PV21', Gemeente::where('code', 'GM0080')->firstOrFail()->provincie->code);
    }

    public function test_provincie_links_to_landsdeel_spatially(): void
    {
        $this->import();

        $this->assertSame('LD01', Provincie::where('code', 'PV20')->firstOrFail()->landsdeel->code);
    }

    public function test_reimport_replaces_rows_without_duplicating(): void
    {
        $this->import();
        $this->import();

        $this->assertDatabaseCount('gemeenten', 2);
        $this->assertDatabaseCount('buurten', 3);
    }
}
