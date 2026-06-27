<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use Illuminate\Database\QueryException;
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

        $this->expectException(QueryException::class);
        DB::table('roadwork_slugs')->insert(['roadwork_id' => $id, 'slug' => 'b', 'is_current' => true]);
    }
}
