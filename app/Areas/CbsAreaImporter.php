<?php

declare(strict_types=1);

namespace App\Areas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Loads the five CBS area layers from a GeoPackage into the area tables.
 *
 * Geometry is bulk-loaded by `ogr2ogr` into per-level staging tables (reprojected
 * to EPSG:4326, polygons promoted to MultiPolygon) — no per-feature PHP. A single
 * transaction then truncates and rebuilds the final tables top-down, resolving each
 * parent: by CBS code where it encodes the parent (buurt→wijk), by the `gm_code`
 * attribute (wijk/buurt→gemeente), and spatially otherwise (gemeente→provincie,
 * provincie→landsdeel) via point-in-polygon containment.
 */
final class CbsAreaImporter
{
    /**
     * CBS layer + staging table for each level, ordered parent-first.
     *
     * @var array<string, array{layer: string, staging: string}>
     */
    private const array LEVELS = [
        'landsdelen' => ['layer' => 'landsdeel_gegeneraliseerd', 'staging' => 'cbs_stg_landsdeel'],
        'provincies' => ['layer' => 'provincie_gegeneraliseerd', 'staging' => 'cbs_stg_provincie'],
        'gemeenten' => ['layer' => 'gemeente_gegeneraliseerd', 'staging' => 'cbs_stg_gemeente'],
        'wijken' => ['layer' => 'wijk_gegeneraliseerd', 'staging' => 'cbs_stg_wijk'],
        'buurten' => ['layer' => 'buurt_gegeneraliseerd', 'staging' => 'cbs_stg_buurt'],
    ];

    /**
     * @return array<string, int> rows imported per level table
     */
    public function import(string $geoPackage, int $year): array
    {
        foreach (self::LEVELS as $level) {
            $this->loadStaging($geoPackage, $level['layer'], $level['staging']);
        }

        DB::transaction(function () use ($year): void {
            DB::statement('TRUNCATE buurten, wijken, gemeenten, provincies, landsdelen RESTART IDENTITY CASCADE');

            DB::insert(
                'INSERT INTO landsdelen (code, name, year, geometry)
                 SELECT statcode, statnaam, ?, geom FROM cbs_stg_landsdeel',
                [$year],
            );

            DB::insert(
                'INSERT INTO provincies (code, name, year, landsdeel_id, geometry)
                 SELECT s.statcode, s.statnaam, ?,
                        (SELECT l.id FROM landsdelen l
                          WHERE ST_Contains(l.geometry, ST_PointOnSurface(s.geom)) LIMIT 1),
                        s.geom
                 FROM cbs_stg_provincie s',
                [$year],
            );

            DB::insert(
                'INSERT INTO gemeenten (code, name, year, provincie_id, geometry)
                 SELECT s.statcode, s.statnaam, ?,
                        (SELECT p.id FROM provincies p
                          WHERE ST_Contains(p.geometry, ST_PointOnSurface(s.geom)) LIMIT 1),
                        s.geom
                 FROM cbs_stg_gemeente s',
                [$year],
            );

            DB::insert(
                'INSERT INTO wijken (code, name, year, gemeente_id, geometry)
                 SELECT s.statcode, s.statnaam, ?,
                        (SELECT g.id FROM gemeenten g WHERE g.code = s.gm_code),
                        s.geom
                 FROM cbs_stg_wijk s',
                [$year],
            );

            // buurt → wijk by code: BU + 8 digits, where the first 6 digits are the
            // wijk number (BU00140000 → WK001400). gemeente comes from gm_code.
            DB::insert(
                "INSERT INTO buurten (code, name, year, wijk_id, gemeente_id, geometry)
                 SELECT s.statcode, s.statnaam, ?,
                        (SELECT w.id FROM wijken w WHERE w.code = 'WK' || substring(s.statcode from 3 for 6)),
                        (SELECT g.id FROM gemeenten g WHERE g.code = s.gm_code),
                        s.geom
                 FROM cbs_stg_buurt s",
                [$year],
            );
        });

        $this->dropStaging();

        return $this->counts();
    }

    private function dropStaging(): void
    {
        foreach (self::LEVELS as $level) {
            DB::statement('DROP TABLE IF EXISTS '.$level['staging']);
        }
    }

    private function loadStaging(string $geoPackage, string $layer, string $staging): void
    {
        $result = Process::run([
            config('cbs.ogr2ogr'),
            '-f', 'PostgreSQL',
            $this->postgresConnectionString(),
            $geoPackage,
            $layer,
            '-nln', $staging,
            '-overwrite',
            '-t_srs', 'EPSG:4326',
            '-nlt', 'MULTIPOLYGON',
            '-lco', 'GEOMETRY_NAME=geom',
            '-lco', 'FID=fid',
            '--config', 'PG_USE_COPY', 'YES',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException("ogr2ogr failed for layer {$layer}: ".$result->errorOutput());
        }
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach (array_keys(self::LEVELS) as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    private function postgresConnectionString(): string
    {
        $c = config('database.connections.'.config('database.default'));

        return sprintf(
            'PG:host=%s port=%s dbname=%s user=%s password=%s',
            $c['host'],
            $c['port'],
            $c['database'],
            $c['username'],
            $c['password'],
        );
    }
}
