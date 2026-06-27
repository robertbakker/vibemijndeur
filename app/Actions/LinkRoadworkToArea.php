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

        // The subquery yields a row only when the situation geometry is a JSON object
        // — a missing key gives SQL NULL and a JSON `null` is filtered out here — so
        // ST_GeomFromGeoJSON never sees the string "null" (which it rejects). No row →
        // NULL geometry → intersects nothing → the links are simply cleared.
        DB::insert(
            "INSERT INTO {$this->pivotTable()} (roadwork_id, {$this->areaKey()})
             SELECT ?, a.id
             FROM {$this->areaTable()} a
             WHERE ST_Intersects(
                 a.geometry,
                 ST_SetSRID(
                     ST_GeomFromGeoJSON(
                         (SELECT feature->'situation'->'geometry'
                          FROM roadworks
                          WHERE id = ?
                            AND jsonb_typeof(feature->'situation'->'geometry') = 'object')::text
                     ),
                     4326
                 )
             )",
            [$roadworkId, $roadworkId],
        );
    }
}
