<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Roadworks\Data\RoadworkDocument;

/**
 * Builds GeoJSON features (situation + restrictions + detours) from a
 * {@see RoadworkDocument}. The geometries are already GeoJSON in the `feature`
 * jsonb; the Manticore index only holds the centroid point, so anything that needs the
 * real lines/polygons reads them through here.
 */
final class RoadworkGeometry
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function features(RoadworkDocument $document, int $roadworkId): array
    {
        $features = [];

        self::append($features, $document->situation?->toArray(), 'situation', $roadworkId);

        foreach ($document->restrictions as $restriction) {
            self::append($features, $restriction->toArray(), 'restriction', $roadworkId);
        }

        foreach ($document->detours as $detour) {
            self::append($features, $detour->toArray(), 'detour', $roadworkId);
        }

        return $features;
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @param  array<string, mixed>|null  $feature
     */
    private static function append(array &$features, ?array $feature, string $role, int $roadworkId): void
    {
        $geometry = $feature['geometry'] ?? null;

        if (! is_array($geometry) || ! isset($geometry['type'], $geometry['coordinates'])) {
            return;
        }

        $features[] = [
            'type' => 'Feature',
            'geometry' => $geometry,
            'properties' => ['role' => $role, 'roadworkId' => $roadworkId],
        ];
    }
}
