<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Models\Roadwork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LinkAreasCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_backfills_links_for_existing_roadworks(): void
    {
        $this->area('gemeenten', 'GM0001', 'POLYGON((0 0,5 0,5 5,0 5,0 0))');
        $this->area('buurten', 'BU00010000', 'POLYGON((0 0,1 0,1 1,0 1,0 0))');

        $inside = $this->roadwork(['type' => 'Point', 'coordinates' => [0.5, 0.5]]);
        $nowhere = $this->roadwork(null);

        $this->artisan('roadworks:link-areas')->assertSuccessful();

        $this->assertSame('BU00010000', Roadwork::findOrFail($inside)->buurten->sole()->code);
        $this->assertSame('GM0001', Roadwork::findOrFail($inside)->gemeenten->sole()->code);
        $this->assertCount(0, Roadwork::findOrFail($nowhere)->buurten);
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
}
