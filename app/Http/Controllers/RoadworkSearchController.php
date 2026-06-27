<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Roadworks\RoadworkSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoadworkSearchController extends Controller
{
    public function __construct(private readonly RoadworkSearch $search) {}

    /**
     * Search roadworks for the interactive map. With a `bbox` the result is the
     * set of roadworks in the viewport; without one it is a free-text lookup the
     * map uses to fly to a location. Either way the body is a GeoJSON
     * FeatureCollection plus Meilisearch facet counts.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'bbox' => ['nullable', 'string', 'regex:/^-?\d+(\.\d+)?(,-?\d+(\.\d+)?){3}$/'],
            'geometry' => ['nullable', 'boolean'],
            'points' => ['nullable', 'boolean'],
            'kind' => ['nullable', 'array'],
            'kind.*' => ['string'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string'],
        ]);

        $query = $validated['q'] ?? '';
        $facets = ['kind', 'status', 'severity'];
        $filters = array_filter([
            'kind' => $validated['kind'] ?? null,
            'status' => $validated['status'] ?? null,
        ]);
        $includeGeometry = (bool) ($validated['geometry'] ?? false);
        $includePoints = (bool) ($validated['points'] ?? true);

        // Only page through the (large) hit set when the caller actually needs
        // the point markers or their geometry; a facets/total-only request can
        // ask for zero hits and stay cheap.
        $limit = $includePoints || $includeGeometry ? 20000 : 0;

        if (isset($validated['bbox'])) {
            [$west, $south, $east, $north] = array_map('floatval', explode(',', $validated['bbox']));
            $raw = $this->search->withinBoundingBox($query, $north, $west, $south, $east, $facets, $filters, $limit, $includeGeometry);
        } else {
            $raw = $this->search->text($query, $facets, $filters, 50);
        }

        $hits = $raw['hits'] ?? [];

        $response = [
            'type' => 'FeatureCollection',
            'features' => $includePoints ? $this->toFeatures($hits) : [],
            'facets' => $raw['facetDistribution'] ?? (object) [],
            'total' => $raw['estimatedTotalHits'] ?? count($hits),
        ];

        if ($includeGeometry) {
            // Each hit carries its stored `geometry` (situation/restrictions/
            // detours); flatten them into one collection for the map's line layers.
            $response['geometry'] = [
                'type' => 'FeatureCollection',
                'features' => $this->collectGeometry($hits),
            ];
        }

        return response()->json($response);
    }

    /**
     * Flatten the stored `geometry` feature lists from every hit into one array.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    private function collectGeometry(array $hits): array
    {
        $features = [];

        foreach ($hits as $hit) {
            foreach ($hit['geometry'] ?? [] as $feature) {
                $features[] = $feature;
            }
        }

        return $features;
    }

    /**
     * Map Meilisearch hits to GeoJSON point features, dropping any without a
     * `_geo` position.
     *
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    private function toFeatures(array $hits): array
    {
        $features = [];

        foreach ($hits as $hit) {
            if (! isset($hit['_geo']['lat'], $hit['_geo']['lng'])) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'id' => $hit['id'],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$hit['_geo']['lng'], $hit['_geo']['lat']],
                ],
                'properties' => [
                    'id' => $hit['id'],
                    'title' => $hit['description'] ?: ($hit['road_authority'] ?? 'Wegwerkzaamheden'),
                    'kind' => $hit['kind'] ?? null,
                    'severity' => $hit['severity'] ?? null,
                    'status' => $hit['status'] ?? null,
                    'authority' => $hit['road_authority'] ?? null,
                    'slug' => $hit['slug'] ?? null,
                ],
            ];
        }

        return $features;
    }
}
