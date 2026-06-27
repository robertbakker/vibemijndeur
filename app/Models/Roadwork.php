<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\RoadworkStatus;
use App\Roadworks\Data\RoadworkDocument;
use App\Roadworks\RoadworkGeometry;
use App\Roadworks\RoadworkType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/**
 * A roadwork mirrored from Melvin.
 *
 * The full GeoJSON document lives in `feature` (cast to {@see RoadworkDocument});
 * `source`, `status`, etc. are promoted columns we filter/index on. `coordinates`
 * is a PostGIS geometry — read it as GeoJSON via {@see scopeWithCoordinatesGeoJson()}.
 *
 * @property RoadworkDocument $feature
 */
#[Guarded(['id', 'sys_period'])]
class Roadwork extends Model
{
    use Searchable;

    protected $table = 'roadworks';

    // Versioning is handled by the temporal `sys_period` column, not Eloquent timestamps.
    public $timestamps = false;

    /**
     * The Meilisearch document. The geometry (Point/LineString/Polygon) is
     * reduced to a single representative point in `_geo` — Meilisearch geo only
     * supports points. True geometry queries still go through PostGIS
     * ({@see scopeNearby()}); Meilisearch handles text + facets + a point-based
     * `_geoRadius`/`_geoBoundingBox` approximation off that representative point.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        [$latitude, $longitude] = $this->representativePoint();

        $status = RoadworkStatus::for($this);

        $document = [
            'id' => $this->id,
            'source' => $this->source,
            'kind' => $this->kind,
            'severity' => $this->severity,
            'status' => $this->status,
            'hindrance' => $this->hindrance,
            'activity_type' => $this->activity_type,
            'published' => (bool) $this->published,
            'road_authority' => $this->road_authority,
            'slug' => $this->currentSlug?->slug,
            'description' => $this->searchableDescription(),
            // Derived facets for the listing page: the lifecycle bucket
            // (active/planned/done), an int for ordering, and the work "soort".
            'status_key' => $status->value,
            'status_order' => $status->order(),
            'work_type' => RoadworkType::for($this)['label'],
            // CBS administrative area the representative point falls in;
            // gemeente + provincie are facets, wijk/buurt are stored context.
            ...$this->administrativeAreas(),
            // Unix timestamps so Meilisearch can range-filter and sort on them.
            'start_ts' => $this->start_date ? strtotime((string) $this->start_date) : null,
            'end_ts' => $this->end_date ? strtotime((string) $this->end_date) : null,
            'last_seen_ts' => $this->last_seen_at ? strtotime((string) $this->last_seen_at) : null,
        ];

        if ($latitude !== null && $longitude !== null) {
            $document['_geo'] = ['lat' => $latitude, 'lng' => $longitude];
        }

        // Full geometry (situation + restrictions + detours) stored alongside the
        // point. It is never searched/filtered — only returned (via
        // attributesToRetrieve) once the map is zoomed in enough to draw lines,
        // so the map never hits the database for geometry.
        $document['geometry'] = RoadworkGeometry::features($this->feature, (int) $this->id);

        return $document;
    }

    /**
     * Index every roadwork except those explicitly unpublished. Melvin leaves
     * `published` null for most rows, so only an explicit `false` excludes one.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->published !== false;
    }

    /**
     * Eager-load the representative point for the whole `scout:import` batch so
     * {@see toSearchableArray()} doesn't issue a per-row PostGIS query.
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->withRepresentativePoint()->withAdministrativeAreas()->with('currentSlug');
    }

    public function currentSlug(): HasOne
    {
        return $this->hasOne(RoadworkSlug::class)->where('is_current', true);
    }

    /**
     * Add `geo_lat` / `geo_lng` columns derived from the geometry.
     * `ST_PointOnSurface` returns a point guaranteed to lie on the geometry
     * (unlike `ST_Centroid`, which can fall outside a concave line/polygon).
     */
    #[Scope]
    protected function withRepresentativePoint(Builder $query): Builder
    {
        return $query
            ->select('roadworks.*')
            ->selectRaw('ST_Y(ST_PointOnSurface(coordinates)) as geo_lat')
            ->selectRaw('ST_X(ST_PointOnSurface(coordinates)) as geo_lng');
    }

    /**
     * Resolve each roadwork's representative point to the CBS administrative
     * area it falls in (buurt → wijk → gemeente → provincie) for the whole
     * import batch, via a single lateral spatial join, so {@see toSearchableArray()}
     * doesn't issue a per-row PostGIS query. Areas are reference data the user
     * loads into the `buurten`/`gemeenten`/… tables; an unmatched point (no
     * area loaded, or offshore) simply yields null columns.
     */
    #[Scope]
    protected function withAdministrativeAreas(Builder $query): Builder
    {
        return $query
            ->leftJoinLateral(function (\Illuminate\Database\Query\Builder $sub): void {
                $sub->from('buurten as b')
                    ->select('b.name as buurt_name', 'b.wijk_id', 'b.gemeente_id')
                    ->whereRaw('ST_Contains(b.geometry, ST_PointOnSurface(roadworks.coordinates))')
                    ->limit(1);
            }, 'area')
            ->leftJoin('wijken as w', 'w.id', '=', 'area.wijk_id')
            ->leftJoin('gemeenten as g', 'g.id', '=', 'area.gemeente_id')
            ->leftJoin('provincies as p', 'p.id', '=', 'g.provincie_id')
            ->addSelect([
                'area.buurt_name',
                'w.name as wijk_name',
                'g.name as gemeente_name',
                'g.code as gemeente_code',
                'p.name as provincie_name',
                'p.code as provincie_code',
            ]);
    }

    /**
     * The administrative area names/codes for the search document. Uses the
     * eager-loaded columns from {@see scopeWithAdministrativeAreas()} when the
     * batch import selected them, else a single lateral PostGIS lookup.
     *
     * @return array{gemeente: ?string, gemeente_code: ?string, provincie: ?string, provincie_code: ?string, wijk: ?string, buurt: ?string}
     */
    private function administrativeAreas(): array
    {
        if (array_key_exists('gemeente_name', $this->attributes)) {
            return [
                'gemeente' => $this->getAttribute('gemeente_name'),
                'gemeente_code' => $this->getAttribute('gemeente_code'),
                'provincie' => $this->getAttribute('provincie_name'),
                'provincie_code' => $this->getAttribute('provincie_code'),
                'wijk' => $this->getAttribute('wijk_name'),
                'buurt' => $this->getAttribute('buurt_name'),
            ];
        }

        $row = DB::selectOne(
            'SELECT g.name AS gemeente, g.code AS gemeente_code,
                    p.name AS provincie, p.code AS provincie_code,
                    w.name AS wijk, area.buurt_name AS buurt
             FROM roadworks r
             LEFT JOIN LATERAL (
                 SELECT b.name AS buurt_name, b.wijk_id, b.gemeente_id
                 FROM buurten b
                 WHERE ST_Contains(b.geometry, ST_PointOnSurface(r.coordinates))
                 LIMIT 1
             ) area ON true
             LEFT JOIN wijken w ON w.id = area.wijk_id
             LEFT JOIN gemeenten g ON g.id = area.gemeente_id
             LEFT JOIN provincies p ON p.id = g.provincie_id
             WHERE r.id = ? AND r.coordinates IS NOT NULL',
            [$this->id],
        );

        return [
            'gemeente' => $row?->gemeente,
            'gemeente_code' => $row?->gemeente_code,
            'provincie' => $row?->provincie,
            'provincie_code' => $row?->provincie_code,
            'wijk' => $row?->wijk,
            'buurt' => $row?->buurt,
        ];
    }

    /**
     * The lat/lng to index. Uses the eager-loaded columns from
     * {@see scopeWithRepresentativePoint()} when present (batch import), else
     * falls back to a single PostGIS lookup (on individual model save).
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function representativePoint(): array
    {
        if ($this->getAttribute('geo_lat') !== null) {
            return [(float) $this->getAttribute('geo_lat'), (float) $this->getAttribute('geo_lng')];
        }

        $point = DB::selectOne(
            'SELECT ST_Y(ST_PointOnSurface(coordinates)) AS lat, ST_X(ST_PointOnSurface(coordinates)) AS lng
             FROM roadworks WHERE id = ? AND coordinates IS NOT NULL',
            [$this->id],
        );

        return [$point?->lat, $point?->lng];
    }

    /**
     * Free-text blob fed to Meilisearch's typo-tolerant search.
     */
    private function searchableDescription(): string
    {
        return implode(' ', array_filter([
            $this->road_authority,
            $this->kind,
            $this->activity_type,
            data_get($this->feature, 'situation.properties.causeDescription'),
        ]));
    }

    protected function casts(): array
    {
        return [
            'feature' => RoadworkDocument::class,
            'published' => 'boolean',
        ];
    }

    /**
     * Add the geometry as a GeoJSON string (`coordinates_geojson`) — the raw
     * geometry column is WKB and not usable directly.
     */
    #[Scope]
    protected function withCoordinatesGeoJson(Builder $query): Builder
    {
        return $query
            ->select('*')
            ->selectRaw('ST_AsGeoJSON(coordinates) as coordinates_geojson');
    }

    /**
     * Restrict to roadworks within `$meters` of a lat/lng point (uses the GiST
     * index via a geography distance check).
     */
    #[Scope]
    protected function nearby(Builder $query, float $latitude, float $longitude, float $meters): Builder
    {
        return $query->whereRaw(
            'ST_DWithin(coordinates::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$longitude, $latitude, $meters],
        );
    }
}
