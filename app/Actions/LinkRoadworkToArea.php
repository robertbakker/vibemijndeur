<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Rebuilds one roadwork's links to a single CBS area level.
 *
 * Membership is a spatial intersect of the roadwork's true situation geometry
 * (`feature->situation->geometry`, which may be a Point, LineString or Polygon)
 * against the level's GiST-indexed boundaries — so a point links to one area and a
 * line to every area it crosses. The link set is replaced wholesale (delete then
 * insert), making the action idempotent and correct when a roadwork's geometry moves
 * or disappears. A missing geometry yields `NULL`, intersects nothing, and clears the
 * links.
 *
 * The intersect's plan is the expensive part: PostGIS planning of the
 * `ST_Intersects` join costs ~8ms, dwarfing the ~0.2ms execution. So the INSERT is a
 * server-side named prepared statement — `PREPARE`d once per database session, then
 * `EXECUTE`d for every roadwork — letting Postgres reuse a generic plan and skip the
 * per-call planning. Across a bulk import this is the whole win.
 *
 * Subclasses bind the level by declaring its pivot table, area table and area key.
 */
abstract class LinkRoadworkToArea
{
    abstract protected function pivotTable(): string;

    abstract protected function areaTable(): string;

    abstract protected function areaKey(): string;

    public function __invoke(int $roadworkId): void
    {
        DB::delete("DELETE FROM {$this->pivotTable()} WHERE roadwork_id = ?", [$roadworkId]);

        $name = 'link_'.$this->areaTable();

        $this->ensurePrepared($name);

        // $roadworkId is a typed int, so it is inlined: bound `?` params would reach
        // EXECUTE as untyped `$n`, which Postgres cannot type-infer (SQLSTATE 42P18).
        DB::statement("EXECUTE {$name}({$roadworkId}, {$roadworkId})");
    }

    /**
     * Prepares the level's INSERT once per session, gating on the live
     * `pg_prepared_statements` catalog rather than a process-side cache. The catalog
     * is the source of truth that survives both a transaction rollback (which drops
     * prepared statements — so a long-lived test process re-prepares per test) and a
     * queue worker reconnecting onto a fresh session. The check is a cheap in-memory
     * read and never errors, so it cannot poison the surrounding transaction.
     */
    private function ensurePrepared(string $name): void
    {
        $alreadyPrepared = DB::selectOne(
            'SELECT 1 FROM pg_prepared_statements WHERE name = ?',
            [$name],
        );

        if ($alreadyPrepared === null) {
            DB::statement($this->prepareSql($name));
        }
    }

    /**
     * The `$1` roadwork id is the inserted FK; `$2` selects the situation geometry.
     *
     * The subquery yields a row only when the situation geometry is a JSON object — a
     * missing key gives SQL NULL and a JSON `null` is filtered out here — so
     * ST_GeomFromGeoJSON never sees the string "null" (which it rejects). No row →
     * NULL geometry → intersects nothing → the links are simply cleared.
     */
    private function prepareSql(string $name): string
    {
        return "PREPARE {$name} (bigint, bigint) AS
            INSERT INTO {$this->pivotTable()} (roadwork_id, {$this->areaKey()})
            SELECT \$1, a.id
            FROM {$this->areaTable()} a
            WHERE ST_Intersects(
                a.geometry,
                ST_SetSRID(
                    ST_GeomFromGeoJSON(
                        (SELECT feature->'situation'->'geometry'
                         FROM roadworks
                         WHERE id = \$2
                           AND jsonb_typeof(feature->'situation'->'geometry') = 'object')::text
                    ),
                    4326
                )
            )";
    }
}
