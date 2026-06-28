<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkSearch;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\RequiresMeilisearch;
use Tests\TestCase;

/**
 * Exercises the live Meilisearch path. Runs against an isolated `testing_`
 * prefixed index (see phpunit.xml) and waits out Meilisearch's asynchronous
 * indexing before asserting.
 */
#[RequiresMeilisearch]
class RoadworkSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $point = ['type' => 'Point', 'coordinates' => [5.1214, 52.0907]]; // Utrecht
        $restriction = ['type' => 'Feature', 'geometry' => ['type' => 'LineString', 'coordinates' => [[5.12, 52.09], [5.13, 52.09]]], 'properties' => []];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => ['causeDescription' => 'Asfalteringswerkzaamheden Domplein']], 'restrictions' => [$restriction], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            'NDW_MAP_1',
            ['kind' => 'WORK', 'severity' => 'high', 'status' => 'running', 'road_authority' => 'Gemeente Utrecht', 'published' => true],
            $point,
            $doc,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        Roadwork::removeAllFromSearch();
        Roadwork::makeAllSearchable();
        Artisan::call('scout:sync-index-settings');
        $this->waitForIndex(expected: 1);
    }

    protected function tearDown(): void
    {
        if (! $this->meilisearchUnavailableForThisTest()) {
            Roadwork::removeAllFromSearch();
        }

        parent::tearDown();
    }

    public function test_bounding_box_search_returns_geojson_features(): void
    {
        $response = $this->getJson('/api/roadworks?bbox=4.9,51.9,5.3,52.2')
            ->assertOk()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonPath('features.0.geometry.type', 'Point')
            ->assertJsonPath('features.0.properties.authority', 'Gemeente Utrecht')
            ->assertJsonStructure(['facets' => ['kind', 'severity', 'status']]);

        $this->assertNotEmpty($response->json('features.0.properties.slug'));
    }

    public function test_text_search_without_bbox_finds_by_description(): void
    {
        $this->getJson('/api/roadworks?q=Domplein')
            ->assertOk()
            ->assertJsonPath('features.0.properties.id', Roadwork::where('source_id', 'NDW_MAP_1')->value('id'));
    }

    public function test_geometry_is_returned_only_when_requested(): void
    {
        // Default: points only, no geometry payload.
        $this->getJson('/api/roadworks?bbox=4.9,51.9,5.3,52.2')
            ->assertOk()
            ->assertJsonMissingPath('geometry');

        // geometry=1: situation + restriction lines included.
        $this->getJson('/api/roadworks?bbox=4.9,51.9,5.3,52.2&geometry=1')
            ->assertOk()
            ->assertJsonPath('geometry.type', 'FeatureCollection')
            ->assertJsonPath('geometry.features.0.properties.role', 'situation')
            ->assertJsonPath('geometry.features.1.properties.role', 'restriction')
            ->assertJsonPath('geometry.features.1.geometry.type', 'LineString');
    }

    public function test_invalid_bbox_is_rejected(): void
    {
        $this->getJson('/api/roadworks?bbox=not,a,box')->assertUnprocessable();
    }

    /**
     * Poll the index until it reflects the expected document count (Meilisearch
     * indexes asynchronously), or give up after ~6 seconds.
     */
    private function waitForIndex(int $expected): void
    {
        $search = app(RoadworkSearch::class);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                if ((int) ($search->text('')['estimatedTotalHits'] ?? 0) === $expected) {
                    return;
                }
            } catch (\Throwable) {
                // Index/settings not ready yet; keep waiting.
            }

            usleep(200_000);
        }

        $this->fail('Meilisearch did not reflect the expected document count in time.');
    }
}
