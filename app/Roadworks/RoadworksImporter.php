<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Melvin\Data\Feature;
use App\Melvin\Data\FeatureCollection;
use App\Melvin\Data\FeatureProperties;

/**
 * Imports a Melvin FeatureCollection into the `roadworks` table.
 *
 * Features are grouped by their shared GeoJSON id: the SITUATION feature is the
 * primary record; its RESTRICTION/DETOUR features are nested into the stored
 * jsonb document. Rows are upserted on (source, source_id), so a re-import only
 * touches rows that actually changed — the temporal trigger records the history.
 */
final readonly class RoadworksImporter
{
    public function __construct(private RoadworkUpserter $upserter) {}

    public function import(FeatureCollection $collection): RoadworksImportResult
    {
        $created = 0;
        $updated = 0;
        $total = 0;

        foreach ($this->group($collection) as $group) {
            if (! $group['situation'] instanceof Feature) {
                // Restriction/detour whose situation isn't in this batch — skip.
                continue;
            }

            $total++;
            $this->upsert($group) ? $created++ : $updated++;
        }

        return new RoadworksImportResult($created, $updated, $total);
    }

    /**
     * @return array<string, array{situation: ?Feature, properties: ?FeatureProperties, restrictions: list<Feature>, detours: list<Feature>}>
     */
    private function group(FeatureCollection $collection): array
    {
        $groups = [];

        foreach ($collection->features as $feature) {
            $id = (string) $feature->id;
            $groups[$id] ??= ['situation' => null, 'properties' => null, 'restrictions' => [], 'detours' => []];

            // Validates the consumed slice; throws on an unexpected feature type.
            $properties = FeatureProperties::validateAndCreate($feature->properties);

            match (true) {
                $properties->isSituation() => $groups[$id] = [...$groups[$id], 'situation' => $feature, 'properties' => $properties],
                $properties->isRestriction() => $groups[$id]['restrictions'][] = $feature,
                $properties->isDetour() => $groups[$id]['detours'][] = $feature,
                default => null,
            };
        }

        return $groups;
    }

    /**
     * @param  array{situation: Feature, properties: FeatureProperties, restrictions: list<Feature>, detours: list<Feature>}  $group
     * @return bool true when a new row was inserted, false when an existing row was updated
     */
    private function upsert(array $group): bool
    {
        $situation = $group['situation'];
        $properties = $group['properties'];

        $point = $situation->geometry;
        $document = [
            'situation' => $situation->toArray(),
            'restrictions' => array_map(static fn (Feature $f): array => $f->toArray(), $group['restrictions']),
            'detours' => array_map(static fn (Feature $f): array => $f->toArray(), $group['detours']),
        ];

        return $this->upserter->upsert(
            'MELVIN',
            $properties->situationId ?? (string) $situation->id,
            [
                'status' => $properties->status,
                'activity_type' => $properties->activityType,
                'published' => $properties->published,
            ],
            $point,
            $document,
            now(),
        );
    }
}
