<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Roadwork;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared configuration for the CBS area models (landsdeel … buurt): they carry no
 * Eloquent timestamps and expose their PostGIS `geometry` column as GeoJSON.
 */
trait IsCbsArea
{
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
