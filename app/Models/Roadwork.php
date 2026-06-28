<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\LinkRoadworkToArea;
use App\Data\RoadworkStatus;
use App\Roadworks\Data\RoadworkDocument;
use App\Roadworks\ManticoreRoadworkSearch;
use App\Roadworks\RoadworkGeometry;
use App\Roadworks\RoadworkType;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
 * @property int $id
 * @property string $source
 * @property string $source_id
 * @property string|null $kind
 * @property string|null $severity
 * @property string|null $status
 * @property string|null $hindrance
 * @property string|null $activity_type
 * @property bool|null $published
 * @property string|null $road_authority
 * @property string|null $start_date
 * @property string|null $end_date
 * @property string|null $coordinates
 * @property string $sys_period
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Buurt> $buurten
 * @property-read int|null $buurten_count
 * @property-read \App\Models\Slug|null $currentSlug
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Gemeente> $gemeenten
 * @property-read int|null $gemeenten_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Landsdeel> $landsdelen
 * @property-read int|null $landsdelen_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Provincie> $provincies
 * @property-read int|null $provincies_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Wijk> $wijken
 * @property-read int|null $wijken_count
 * @method static Builder<static>|Roadwork nearby(float $latitude, float $longitude, float $meters)
 * @method static Builder<static>|Roadwork newModelQuery()
 * @method static Builder<static>|Roadwork newQuery()
 * @method static Builder<static>|Roadwork query()
 * @method static Builder<static>|Roadwork whereActivityType($value)
 * @method static Builder<static>|Roadwork whereCoordinates($value)
 * @method static Builder<static>|Roadwork whereEndDate($value)
 * @method static Builder<static>|Roadwork whereFeature($value)
 * @method static Builder<static>|Roadwork whereHindrance($value)
 * @method static Builder<static>|Roadwork whereId($value)
 * @method static Builder<static>|Roadwork whereKind($value)
 * @method static Builder<static>|Roadwork wherePublished($value)
 * @method static Builder<static>|Roadwork whereRoadAuthority($value)
 * @method static Builder<static>|Roadwork whereSeverity($value)
 * @method static Builder<static>|Roadwork whereSource($value)
 * @method static Builder<static>|Roadwork whereSourceId($value)
 * @method static Builder<static>|Roadwork whereStartDate($value)
 * @method static Builder<static>|Roadwork whereStatus($value)
 * @method static Builder<static>|Roadwork whereSysPeriod($value)
 * @method static Builder<static>|Roadwork withAdministrativeAreas()
 * @method static Builder<static>|Roadwork withCoordinatesGeoJson()
 * @method static Builder<static>|Roadwork withRepresentativePoint()
 * @mixin \Eloquent
 */
#[Guarded(['id', 'sys_period'])]
#[Table(name: 'roadworks')]
#[WithoutTimestamps]
class Roadwork extends Model
{
    use Searchable;

    /**
     * The indexed search document. The geometry (Point/LineString/Polygon) is
     * reduced to a single representative point in `_geo` — the index's geo
     * filtering only supports points. True geometry queries still go through
     * PostGIS ({@see scopeNearby()}); the Manticore index handles text + facets
     * + a point-based radius/bounding-box approximation off that representative
     * point.
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
            // Unix timestamps so the search engine can range-filter and sort on them.
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
     * Manticore table schema (mirrors {@see toSearchableArray()} fields). Used
     * by the Manticore engine ({@see ManticoreRoadworkSearch}) and the
     * `manticore:build-roadworks` builder. `_geo` is split into `lat`/`lng`
     * floats (for GEODIST); `geometry` is stored JSON, returned but never
     * matched/filtered.
     *
     * @return array{fields: array<string, array{type: string}>, settings: array<string, string>}
     */
    public function scoutIndexMigration(): array
    {
        $string = ['type' => 'string'];

        return [
            'fields' => [
                'description' => ['type' => 'text'],
                'source' => $string,
                'kind' => $string,
                'severity' => $string,
                'status' => $string,
                'status_key' => $string,
                'status_order' => ['type' => 'int'],
                'work_type' => $string,
                'hindrance' => $string,
                'activity_type' => $string,
                'road_authority' => $string,
                'gemeente' => $string,
                'gemeente_code' => $string,
                'provincie' => $string,
                'provincie_code' => $string,
                'wijk' => $string,
                'buurt' => $string,
                'slug' => $string,
                'published' => ['type' => 'int'],
                'lat' => ['type' => 'float'],
                'lng' => ['type' => 'float'],
                'start_ts' => ['type' => 'bigint'],
                'end_ts' => ['type' => 'bigint'],
                'last_seen_ts' => ['type' => 'bigint'],
                'geometry' => ['type' => 'json'],
            ],
            'settings' => [
                'min_infix_len' => '2',
            ],
        ];
    }

    /**
     * The {@see scoutIndexMigration()} document for this roadwork, as Manticore
     * column => value (id returned separately). `_geo` is flattened to lat/lng,
     * nullable scalars are coalesced to the column's zero/empty value, and the
     * geometry feature list is JSON-encoded for the stored `json` column.
     *
     * @return array{id: int, attributes: array<string, mixed>}
     */
    public function toManticoreDocument(): array
    {
        $document = $this->toSearchableArray();

        $strings = [
            'source', 'kind', 'severity', 'status', 'status_key', 'work_type',
            'hindrance', 'activity_type', 'road_authority', 'gemeente', 'gemeente_code',
            'provincie', 'provincie_code', 'wijk', 'buurt', 'slug', 'description',
        ];

        $attributes = [];
        foreach ($strings as $key) {
            $attributes[$key] = (string) ($document[$key] ?? '');
        }

        $attributes['status_order'] = (int) ($document['status_order'] ?? 0);
        $attributes['published'] = empty($document['published']) ? 0 : 1;
        // Floats are passed as strings: the Manticore client binds float values
        // as PDO::PARAM_INT (truncating them), so a string preserves precision.
        $attributes['lat'] = sprintf('%.7f', (float) ($document['_geo']['lat'] ?? 0));
        $attributes['lng'] = sprintf('%.7f', (float) ($document['_geo']['lng'] ?? 0));
        $attributes['start_ts'] = (int) ($document['start_ts'] ?? 0);
        $attributes['end_ts'] = (int) ($document['end_ts'] ?? 0);
        $attributes['last_seen_ts'] = (int) ($document['last_seen_ts'] ?? 0);
        $attributes['geometry'] = json_encode($document['geometry'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';

        return ['id' => (int) $document['id'], 'attributes' => $attributes];
    }

    /**
     * Eager-load the representative point for the whole `scout:import` batch so
     * {@see toSearchableArray()} doesn't issue a per-row PostGIS query.
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->withRepresentativePoint()->withAdministrativeAreas()->with('currentSlug');
    }

    public function currentSlug(): MorphOne
    {
        return $this->morphOne(Slug::class, 'sluggable')->where('is_current', true);
    }

    /**
     * The CBS areas this roadwork's geometry intersects, by level. Links are
     * maintained by the {@see LinkRoadworkToArea} actions.
     */
    public function landsdelen(): BelongsToMany
    {
        return $this->belongsToMany(Landsdeel::class, 'roadwork_landsdeel', 'roadwork_id', 'landsdeel_id');
    }

    public function provincies(): BelongsToMany
    {
        return $this->belongsToMany(Provincie::class, 'roadwork_provincie', 'roadwork_id', 'provincie_id');
    }

    public function gemeenten(): BelongsToMany
    {
        return $this->belongsToMany(Gemeente::class, 'roadwork_gemeente', 'roadwork_id', 'gemeente_id');
    }

    public function wijken(): BelongsToMany
    {
        return $this->belongsToMany(Wijk::class, 'roadwork_wijk', 'roadwork_id', 'wijk_id');
    }

    public function buurten(): BelongsToMany
    {
        return $this->belongsToMany(Buurt::class, 'roadwork_buurt', 'roadwork_id', 'buurt_id');
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
     * Free-text blob fed to the search engine's typo-tolerant search.
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

    #[\Override]
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
