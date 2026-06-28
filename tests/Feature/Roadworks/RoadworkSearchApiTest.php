<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use RomanStruk\ManticoreScoutEngine\Mysql\Builder;
use Tests\TestCase;

/**
 * Exercises the search API against the Manticore engine. Roadworks are seeded in
 * Postgres and mirrored into a disposable `testing_roadworks` index so the
 * controller resolves real documents. Skips when Manticore is unreachable.
 */
class RoadworkSearchApiTest extends TestCase
{
    use RefreshDatabase;

    private const string INDEX = 'testing_roadworks';

    protected function setUp(): void
    {
        parent::setUp();

        config(['roadwork.manticore_index' => self::INDEX]);

        try {
            $this->dropIndex();
            app(EngineManager::class)->driver('manticore')->createIndex(self::INDEX, (new Roadwork)->scoutIndexMigration());
        } catch (\Throwable $e) {
            $this->markTestSkipped('Manticore not available: '.$e->getMessage());
        }

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

        $this->syncToManticore();
    }

    protected function tearDown(): void
    {
        $this->dropIndex();

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
     * Mirror every seeded roadwork into the Manticore test index, the same shape
     * `manticore:build-roadworks` produces. Manticore's RT index is synchronous,
     * so the documents are searchable immediately.
     */
    private function syncToManticore(): void
    {
        foreach (Roadwork::query()->with('currentSlug')->get() as $roadwork) {
            $document = $roadwork->toManticoreDocument();
            app(Builder::class)->index(self::INDEX)->replace($document['attributes'], $document['id']);
        }
    }

    private function dropIndex(): void
    {
        try {
            app(Builder::class)->index(self::INDEX)->drop();
        } catch (\Throwable) {
            // Index did not exist.
        }
    }
}
