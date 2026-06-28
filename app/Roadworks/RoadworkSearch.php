<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\Contracts\RoadworkSearchEngine;
use Meilisearch\Contracts\FacetSearchQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\FacetSearchResult;

/**
 * Geo + faceted search over the Meilisearch roadworks index.
 *
 * Scout's fluent builder has no geo support, so every method drops to a raw
 * Meilisearch options closure (filter / sort / facets) and returns the raw
 * response via `->raw()` — `->get()` would hydrate models but discard
 * `facetDistribution` and the per-hit `_geoDistance`.
 */
class RoadworkSearch implements RoadworkSearchEngine
{
    /**
     * The small per-hit fields needed to render a marker. Requested explicitly
     * (via `attributesToRetrieve`) so the much larger `geometry` field is only
     * shipped when the caller asks for it.
     *
     * @var list<string>
     */
    private const array POINT_ATTRIBUTES = ['id', 'kind', 'severity', 'status', 'road_authority', 'description', 'slug', '_geo'];

    /**
     * Roadworks whose representative point is within `$meters` of a lat/lng,
     * nearest first, with facet counts for the given attributes.
     *
     * @param  list<string>  $facets  filterable attributes to return counts for, e.g. ['status', 'kind']
     * @param  array<string, string|int|bool|list<string|int>>  $filters  extra scalar facet filters, e.g. ['status' => 'active']
     * @return array<string, mixed> raw Meilisearch response (`hits`, `facetDistribution`, `estimatedTotalHits`, ...)
     */
    public function nearby(string $query, float $latitude, float $longitude, int $meters, array $facets = [], array $filters = [], int $limit = 20): array
    {
        $filter = array_merge(
            ["_geoRadius({$latitude}, {$longitude}, {$meters})"],
            $this->scalarFilters($filters),
        );

        return Roadwork::search($query, function (Indexes $index, string $query, array $options) use ($latitude, $longitude, $filter, $facets, $limit) {
            $options['filter'] = $filter;
            // Sorting by `_geoPoint` also makes Meilisearch attach `_geoDistance` (metres) to each hit.
            $options['sort'] = ["_geoPoint({$latitude}, {$longitude}):asc"];
            $options['facets'] = $facets;
            $options['limit'] = $limit;

            return $index->search($query, $options);
        })->raw();
    }

    /**
     * Roadworks inside a bounding box, with facets. Ideal for "what's in the
     * current map viewport". Meilisearch's `_geoBoundingBox` expects the
     * top-right and bottom-left corners, so the box is assembled from the
     * north/east and south/west extremes.
     *
     * Set `$includeGeometry` (only worth doing when zoomed in) to also return
     * each hit's stored `geometry` field — the situation/restriction/detour
     * GeoJSON — so the map can draw lines without a database round-trip.
     *
     * @param  list<string>  $facets
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @return array<string, mixed>
     */
    public function withinBoundingBox(string $query, float $topLat, float $leftLng, float $bottomLat, float $rightLng, array $facets = [], array $filters = [], int $limit = 100, bool $includeGeometry = false): array
    {
        $filter = array_merge(
            ["_geoBoundingBox([{$topLat}, {$rightLng}], [{$bottomLat}, {$leftLng}])"],
            $this->scalarFilters($filters),
        );

        $attributes = $includeGeometry
            ? [...self::POINT_ATTRIBUTES, 'geometry']
            : self::POINT_ATTRIBUTES;

        return Roadwork::search($query, function (Indexes $index, string $query, array $options) use ($filter, $facets, $limit, $attributes) {
            $options['filter'] = $filter;
            $options['facets'] = $facets;
            $options['limit'] = $limit;
            $options['attributesToRetrieve'] = $attributes;

            return $index->search($query, $options);
        })->raw();
    }

    /**
     * Free-text / faceted search with no geo constraint. Used to locate a
     * roadwork by name or postcode-ish term so the map can fly to it.
     *
     * @param  list<string>  $facets
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @return array<string, mixed>
     */
    public function text(string $query, array $facets = [], array $filters = [], int $limit = 50): array
    {
        $filter = $this->scalarFilters($filters);

        return Roadwork::search($query, function (Indexes $index, string $query, array $options) use ($filter, $facets, $limit) {
            if ($filter !== []) {
                $options['filter'] = $filter;
            }
            $options['facets'] = $facets;
            $options['limit'] = $limit;

            return $index->search($query, $options);
        })->raw();
    }

    /**
     * Paged, sorted, faceted listing search (no geo). Returns only hit ids
     * (the caller hydrates models) plus `facetDistribution` and
     * `estimatedTotalHits`. A zero `$limit` yields a counts-only response.
     *
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @param  list<string>  $sort  e.g. ['start_ts:asc']
     * @param  list<string>  $facets
     * @param  array<string, list<string>>  $areaFilters  OR'd together as one group
     * @return array<string, mixed>
     */
    public function browse(string $query, array $filters = [], array $sort = [], int $offset = 0, int $limit = 24, array $facets = [], array $areaFilters = []): array
    {
        $filter = $this->buildFilter($filters, $areaFilters);

        return Roadwork::search($query, function (Indexes $index, string $query, array $options) use ($filter, $sort, $offset, $limit, $facets) {
            if ($filter !== []) {
                $options['filter'] = $filter;
            }
            if ($sort !== []) {
                $options['sort'] = $sort;
            }
            $options['facets'] = $facets;
            $options['offset'] = $offset;
            $options['limit'] = $limit;
            $options['attributesToRetrieve'] = ['id'];

            return $index->search($query, $options);
        })->raw();
    }

    /**
     * Matching facet values for an autosuggest term, with document counts.
     * Wraps Meilisearch facet search, which matches the term against the facet
     * value itself with prefix + typo tolerance (no full-text query, no extra
     * filter — the index only holds searchable/published documents).
     *
     * @return list<array{value: string, count: int}>
     */
    public function facetValues(string $facet, string $term, int $limit = 20): array
    {
        $result = Roadwork::search('', fn (Indexes $index): FacetSearchResult => $index->facetSearch(
            (new FacetSearchQuery)
                ->setFacetName($facet)
                ->setFacetQuery(trim($term)),
        ))->raw();

        $hits = $result instanceof FacetSearchResult
            ? $result->getFacetHits()
            : ($result['facetHits'] ?? []);

        return array_values(array_map(
            static fn (array $hit): array => ['value' => (string) $hit['value'], 'count' => (int) $hit['count']],
            array_slice($hits, 0, $limit),
        ));
    }

    /**
     * Turn `['status' => 'active', 'kind' => ['repair', 'event']]` into
     * Meilisearch filter expressions (`status = "active"`, `kind IN ["repair","event"]`).
     *
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @return list<string>
     */
    /**
     * Combine AND'd dimension filters with a single OR'd area group. A nested
     * array in a Meilisearch filter list is interpreted as OR.
     *
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     * @param  array<string, list<string>>  $areaFilters
     * @return list<string|list<string>>
     */
    protected function buildFilter(array $filters, array $areaFilters): array
    {
        $filter = $this->scalarFilters($filters);

        $areaGroup = $this->scalarFilters($areaFilters);
        if (count($areaGroup) === 1) {
            $filter[] = $areaGroup[0];
        } elseif (count($areaGroup) > 1) {
            $filter[] = $areaGroup; // nested array = OR in Meilisearch
        }

        return $filter;
    }

    private function scalarFilters(array $filters): array
    {
        $expressions = [];

        foreach ($filters as $attribute => $value) {
            if (is_array($value)) {
                $quoted = implode(', ', array_map($this->quote(...), $value));
                $expressions[] = "{$attribute} IN [{$quoted}]";
            } else {
                $expressions[] = "{$attribute} = {$this->quote($value)}";
            }
        }

        return $expressions;
    }

    private function quote(string|int|bool $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return '"'.str_replace('"', '\"', $value).'"';
    }
}
