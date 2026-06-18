<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadworkGeometryTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_situation_restriction_and_detour_features(): void
    {
        $situation = ['type' => 'Point', 'coordinates' => [5.5, 51.43]];
        $document = [
            'situation' => ['type' => 'Feature', 'geometry' => $situation, 'properties' => ['causeDescription' => 'Werk']],
            'restrictions' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[5.50, 51.43], [5.51, 51.43]]], 'properties' => []],
            ],
            'detours' => [
                ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[5.49, 51.43], [5.50, 51.44]]], 'properties' => []],
            ],
        ];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            'NDW_GEOM_1',
            ['kind' => 'WORK', 'published' => true],
            $situation,
            $document,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        $id = Roadwork::where('source_id', 'NDW_GEOM_1')->value('id');

        $response = $this->getJson("/api/roadworks/{$id}/geometry");

        $response->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonCount(3, 'features')
            ->assertJsonPath('features.0.properties.role', 'situation')
            ->assertJsonPath('features.1.properties.role', 'restriction')
            ->assertJsonPath('features.2.properties.role', 'detour')
            ->assertJsonPath('features.2.geometry.type', 'LineString');
    }

    public function test_missing_roadwork_returns_404(): void
    {
        $this->getJson('/api/roadworks/999999999/geometry')->assertNotFound();
    }
}
