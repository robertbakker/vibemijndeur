<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Models\Gemeente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoadworkAreaPivotSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pivot_table_exists_for_every_level(): void
    {
        foreach (['roadwork_landsdeel', 'roadwork_provincie', 'roadwork_gemeente', 'roadwork_wijk', 'roadwork_buurt'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'roadwork_id'), "$table.roadwork_id");
        }

        $this->assertTrue(Schema::hasColumn('roadwork_gemeente', 'gemeente_id'));
        $this->assertTrue(Schema::hasColumn('roadwork_buurt', 'buurt_id'));
    }

    public function test_deleting_a_gemeente_cascades_to_the_pivot(): void
    {
        $roadwork = $this->insertRoadwork();
        $gemeente = Gemeente::factory()->create();
        DB::table('roadwork_gemeente')->insert(['roadwork_id' => $roadwork, 'gemeente_id' => $gemeente->id]);

        $gemeente->delete();

        $this->assertDatabaseEmpty('roadwork_gemeente');
    }

    private function insertRoadwork(): int
    {
        return (int) DB::selectOne(
            "INSERT INTO roadworks (source, source_id, feature) VALUES ('test', '1', '{}'::jsonb) RETURNING id",
        )->id;
    }
}
