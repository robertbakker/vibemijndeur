<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Roadwork;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Shared configuration for the CBS area models (landsdeel … buurt): they carry no
 * Eloquent timestamps, expose their PostGIS `geometry` column as GeoJSON, and link
 * back to the roadworks that intersect them.
 *
 * Each model declares its pivot table and area key via {@see roadworkPivotTable()}
 * and {@see roadworkForeignKey()}.
 */
trait IsCbsArea
{
    abstract protected function roadworkPivotTable(): string;

    abstract protected function roadworkForeignKey(): string;

    /**
     * The roadworks whose geometry intersects this area.
     */
    public function roadworks(): BelongsToMany
    {
        return $this->belongsToMany(Roadwork::class, $this->roadworkPivotTable(), $this->roadworkForeignKey(), 'roadwork_id');
    }

    /**
     * Add the boundary as a GeoJSON string (`geometry_geojson`) — the raw geometry
     * column is WKB and not usable directly. Mirrors
     * {@see Roadwork::scopeWithCoordinatesGeoJson()}.
     */
    #[Scope]
    protected function withGeoJson(Builder $query): Builder
    {
        return $query
            ->select($query->getModel()->getTable().'.*')
            ->selectRaw('ST_AsGeoJSON(geometry) as geometry_geojson');
    }
}
