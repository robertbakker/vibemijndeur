<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AreasSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_level_has_a_table_with_code_name_and_geometry(): void
    {
        foreach (['landsdelen', 'provincies', 'gemeenten', 'wijken', 'buurten'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['code', 'name', 'year', 'geometry']), "$table columns");
        }
    }

    public function test_parent_foreign_keys_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('provincies', 'landsdeel_id'));
        $this->assertTrue(Schema::hasColumn('gemeenten', 'provincie_id'));
        $this->assertTrue(Schema::hasColumn('wijken', 'gemeente_id'));
        $this->assertTrue(Schema::hasColumns('buurten', ['wijk_id', 'gemeente_id']));
    }

    public function test_geometry_is_postgis_multipolygon_in_4326(): void
    {
        $column = DB::selectOne(
            "SELECT type, srid FROM geometry_columns WHERE f_table_name = 'gemeenten' AND f_geometry_column = 'geometry'",
        );

        $this->assertSame('MULTIPOLYGON', $column?->type);
        $this->assertSame(4326, (int) $column?->srid);
    }

    public function test_codes_are_unique(): void
    {
        $square = "ST_Multi(ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326))";
        DB::statement("INSERT INTO landsdelen (code, name, year, geometry) VALUES ('LD01', 'Noord', 2024, $square)");

        $this->expectException(QueryException::class);
        DB::statement("INSERT INTO landsdelen (code, name, year, geometry) VALUES ('LD01', 'Dup', 2024, $square)");
    }
}
