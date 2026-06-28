<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Roadworks\Contracts\RoadworkSearchEngine;
use RomanStruk\ManticoreScoutEngine\Mysql\Builder;

/**
 * Manticore Search implementation of the roadworks search contract.
 *
 * Queries run through the package's mysql Builder (PDO on 9306) — NOT via Scout,
 * which Meili holds as the global driver — and are normalized to the same shape
 * the Meili implementation returns (`hits` / `facetDistribution` /
 * `estimatedTotalHits`) so the two can be A/B'd behind {@see RoadworkSearchEngine}.
 *
 * Full-text goes through Manticore `MATCH()`, scalar facets through `FACET`
 * (respecting the WHERE filter, so disjunctive counts work), geo through
 * `GEODIST`. The whole corpus is small (single-digit-thousand → ~1M target), so
 * `max_matches` is set high enough that facet counts and `total_found` cover the
 * full match set.
 */
final readonly class ManticoreRoadworkSearch implements RoadworkSearchEngine
{
    /**
     * The per-hit columns the map needs — mirrors the Meili `POINT_ATTRIBUTES`
     * allowlist. `lat`/`lng` are remapped to a `_geo` shape on the way out.
     *
     * @var list<string>
     */
    private const array POINT_COLUMNS = ['id', 'kind', 'severity', 'status', 'road_authority', 'description', 'slug', 'lat', 'lng'];

    /**
     * Keep all matched rows in scope for faceting/counting at this corpus size.
     */
    private const string MAX_MATCHES = '50000';

    private string $index;

    public function __construct()
    {
        $this->index = (string) config('roadwork.manticore_index', 'roadworks');
    }

    public function nearby(string $query, float $latitude, float $longitude, int $meters, array $facets = [], array $filters = [], int $limit = 20): array
    {
        // GEODIST with its `{...}` options can't sit in a raw WHERE; compute it
        // as a select alias and filter/sort on the alias instead.
        $distance = "geodist(lat, lng, {$latitude}, {$longitude}, {in=degrees, out=meters})";

        $builder = $this->newQuery()
            ->search($query)
            ->select(self::POINT_COLUMNS)
            ->selectRaw("{$distance} as _geoDistance")
            ->whereRaw('_geoDistance < ?', [$meters])
            ->orderByRaw('_geoDistance asc');

        $this->applyScalarFilters($builder, $filters);
        $this->applyFacets($builder, $facets);
        $builder->take($limit);

        $result = $this->run($builder);

        return $this->pointResponse($result, $facets, false);
    }

    public function withinBoundingBox(string $query, float $topLat, float $leftLng, float $bottomLat, float $rightLng, array $facets = [], array $filters = [], int $limit = 100, bool $includeGeometry = false): array
    {
        $columns = $includeGeometry ? [...self::POINT_COLUMNS, 'geometry'] : self::POINT_COLUMNS;

        // Bounds are inlined (not bound): the client binds float parameters as
        // PDO::PARAM_INT, which would truncate the box to whole degrees.
        $builder = $this->newQuery()
            ->search($query)
            ->select($columns)
            ->whereRaw(sprintf('lat between %.8f and %.8f', $bottomLat, $topLat))
            ->whereRaw(sprintf('lng between %.8f and %.8f', $leftLng, $rightLng));

        $this->applyScalarFilters($builder, $filters);
        $this->applyFacets($builder, $facets);
        $builder->take($limit);

        $result = $this->run($builder);

        return $this->pointResponse($result, $facets, $includeGeometry);
    }

    public function text(string $query, array $facets = [], array $filters = [], int $limit = 50): array
    {
        $builder = $this->newQuery()
            ->search($query)
            ->select(self::POINT_COLUMNS);

        $this->applyScalarFilters($builder, $filters);
        $this->applyFacets($builder, $facets);
        $builder->take($limit);

        $result = $this->run($builder);

        return $this->pointResponse($result, $facets, false);
    }

    public function browse(string $query, array $filters = [], array $sort = [], int $offset = 0, int $limit = 24, array $facets = [], array $areaFilters = []): array
    {
        $builder = $this->newQuery()
            ->search($query)
            ->select(['id']);

        $this->applyScalarFilters($builder, $filters);
        $this->applyAreaFilters($builder, $areaFilters);

        foreach ($sort as $expression) {
            [$column, $direction] = array_pad(explode(':', $expression, 2), 2, 'asc');
            $builder->orderBy($column, $direction);
        }

        $this->applyFacets($builder, $facets);
        $builder->offset($offset)->take(max($limit, 0));

        $result = $this->run($builder);

        $hits = array_map(static fn (array $hit): array => ['id' => (int) $hit['id']], $result['hits'] ?? []);

        return [
            'hits' => $hits,
            'facetDistribution' => $this->facetDistribution($result, $facets),
            'estimatedTotalHits' => $this->total($result, $hits),
        ];
    }

    public function facetValues(string $facet, string $term, int $limit = 20): array
    {
        $builder = $this->newQuery()
            ->select([$facet])
            ->selectRaw('count(*) as cnt')
            ->groupBy($facet)
            ->orderByRaw('count(*) desc')
            ->take(1000)
            ->option('max_matches', self::MAX_MATCHES);

        $rows = $builder->runSelect()['hits'] ?? [];

        $term = trim($term);
        $values = [];
        foreach ($rows as $row) {
            $value = (string) ($row[$facet] ?? '');
            if ($value === '') {
                continue;
            }
            if ($term !== '' && mb_stripos($value, $term) === false) {
                continue;
            }
            $values[] = ['value' => $value, 'count' => (int) ($row['cnt'] ?? 0)];
        }

        return array_slice($values, 0, $limit);
    }

    private function newQuery(): Builder
    {
        return app(Builder::class)->index($this->index);
    }

    /**
     * Run a search with a generous `max_matches` and `SHOW META` so facet counts
     * and `total_found` reflect the full match set, not just the page.
     *
     * @return array{hits: list<array<string, mixed>>, facets: array<string, mixed>, meta: array<string, mixed>}
     */
    private function run(Builder $builder): array
    {
        return $builder->option('max_matches', self::MAX_MATCHES)->meta()->runSelect();
    }

    /**
     * @param  array<string, string|int|bool|list<string|int>>  $filters
     */
    private function applyScalarFilters(Builder $builder, array $filters): void
    {
        foreach ($filters as $attribute => $value) {
            if (is_array($value)) {
                $builder->whereIn($attribute, array_values($value));
            } else {
                $builder->where($attribute, '=', $value);
            }
        }
    }

    /**
     * A single OR group across the area dimensions (matches Meili, where a
     * nested filter array is OR'd): `(gemeente IN (...) OR provincie IN (...))`.
     *
     * @param  array<string, list<string>>  $areaFilters
     */
    private function applyAreaFilters(Builder $builder, array $areaFilters): void
    {
        $areaFilters = array_filter($areaFilters, static fn (array $values): bool => $values !== []);
        if ($areaFilters === []) {
            return;
        }

        $builder->whereNested(function (Builder $nested) use ($areaFilters): void {
            $first = true;
            foreach ($areaFilters as $attribute => $values) {
                $nested->whereIn($attribute, array_values($values), $first ? 'and' : 'or');
                $first = false;
            }
        });
    }

    /**
     * @param  list<string>  $facets
     */
    private function applyFacets(Builder $builder, array $facets): void
    {
        foreach ($facets as $facet) {
            // FACET <f> BY <f> ORDER BY COUNT(*) DESC LIMIT 1000 — count-desc,
            // uncapped enough for the gemeente distribution (~340 values).
            $builder->facet($facet, $facet, 1000, 'count(*)', 'desc');
        }
    }

    /**
     * Normalize the map/point response: rebuild `_geo` from lat/lng, decode the
     * stored geometry JSON, and shape facet counts like Meilisearch.
     *
     * @param  array{hits: list<array<string, mixed>>, facets: array<string, mixed>, meta: array<string, mixed>}  $result
     * @param  list<string>  $facets
     * @return array<string, mixed>
     */
    private function pointResponse(array $result, array $facets, bool $includeGeometry): array
    {
        $hits = array_map(function (array $hit) use ($includeGeometry): array {
            $point = [
                'id' => (int) $hit['id'],
                'kind' => $hit['kind'] ?? null,
                'severity' => $hit['severity'] ?? null,
                'status' => $hit['status'] ?? null,
                'road_authority' => $hit['road_authority'] ?? null,
                'description' => $hit['description'] ?? null,
                'slug' => ($hit['slug'] ?? '') !== '' ? $hit['slug'] : null,
                '_geo' => ['lat' => (float) ($hit['lat'] ?? 0), 'lng' => (float) ($hit['lng'] ?? 0)],
            ];

            if ($includeGeometry) {
                $geometry = $hit['geometry'] ?? null;
                $point['geometry'] = is_string($geometry) ? (json_decode($geometry, true) ?: []) : ($geometry ?: []);
            }

            return $point;
        }, $result['hits'] ?? []);

        return [
            'hits' => $hits,
            'facetDistribution' => $this->facetDistribution($result, $facets),
            'estimatedTotalHits' => $this->total($result, $hits),
        ];
    }

    /**
     * Convert the Builder's formatted facets (`[field => [['key'=>v,'count'=>n]]]`)
     * into Meilisearch's `[field => [value => count]]`.
     *
     * @param  array{facets: array<string, mixed>}  $result
     * @param  list<string>  $facets
     * @return array<string, array<string, int>>
     */
    private function facetDistribution(array $result, array $facets): array
    {
        $formatted = $result['facets'] ?? [];

        $distribution = [];
        foreach ($facets as $facet) {
            $rows = $formatted[$facet] ?? [];
            $counts = [];
            foreach ($rows as $row) {
                if (! isset($row['key'])) {
                    continue;
                }
                $value = (string) $row['key'];
                // Meili omits null/empty facet values from the distribution; a
                // missing gemeente/status indexes as '' here, so skip it too.
                if ($value === '') {
                    continue;
                }
                $counts[$value] = (int) ($row['count'] ?? 0);
            }
            $distribution[$facet] = $counts;
        }

        return $distribution;
    }

    /**
     * @param  array{meta: array<string, mixed>}  $result
     * @param  list<array<string, mixed>>  $hits
     */
    private function total(array $result, array $hits): int
    {
        $meta = $result['meta'] ?? [];

        return (int) ($meta['total_found'] ?? $meta['total'] ?? count($hits));
    }
}
