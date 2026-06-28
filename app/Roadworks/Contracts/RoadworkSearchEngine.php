<?php

declare(strict_types=1);

namespace App\Roadworks\Contracts;

use App\Roadworks\ManticoreRoadworkSearch;
use App\Roadworks\RoadworkSearch;

/**
 * Geo + faceted search over the roadworks index, independent of the backing
 * engine. The Meilisearch implementation ({@see RoadworkSearch})
 * and the Manticore implementation ({@see ManticoreRoadworkSearch})
 * return the same array shapes so consumers (the map API, the listing, the
 * footer counts, autosuggest) work against either, selected by config.
 *
 * Every method returns a normalized array shaped like a Meilisearch response:
 * `hits` (list of per-hit attribute maps), `facetDistribution`
 * (attribute => value => count) and `estimatedTotalHits`.
 */
interface RoadworkSearchEngine
{
    /**
     * Roadworks whose representative point is within `$meters` of a lat/lng,
     * nearest first, with facet counts for the given attributes.
     *
     * @param  list<string>  $facets
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @return array<string, mixed>
     */
    public function nearby(string $query, float $latitude, float $longitude, int $meters, array $facets = [], array $filters = [], int $limit = 20): array;

    /**
     * Roadworks inside a bounding box, with facets. `$includeGeometry` also
     * returns each hit's stored `geometry` field for the map's line layers.
     *
     * @param  list<string>  $facets
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @return array<string, mixed>
     */
    public function withinBoundingBox(string $query, float $topLat, float $leftLng, float $bottomLat, float $rightLng, array $facets = [], array $filters = [], int $limit = 100, bool $includeGeometry = false): array;

    /**
     * Free-text / faceted search with no geo constraint.
     *
     * @param  list<string>  $facets
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @return array<string, mixed>
     */
    public function text(string $query, array $facets = [], array $filters = [], int $limit = 50): array;

    /**
     * Paged, sorted, faceted listing search (no geo). Returns hit ids plus
     * `facetDistribution` and `estimatedTotalHits`. A zero `$limit` yields a
     * counts-only response.
     *
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @param  list<string>  $sort  e.g. ['start_ts:asc']
     * @param  list<string>  $facets
     * @param  array<string, list<string>>  $areaFilters  OR'd together as one group
     * @return array<string, mixed>
     */
    public function browse(string $query, array $filters = [], array $sort = [], int $offset = 0, int $limit = 24, array $facets = [], array $areaFilters = []): array;

    /**
     * Matching facet values for an autosuggest term, with document counts.
     *
     * @return list<array{value: string, count: int}>
     */
    public function facetValues(string $facet, string $term, int $limit = 20): array;
}
