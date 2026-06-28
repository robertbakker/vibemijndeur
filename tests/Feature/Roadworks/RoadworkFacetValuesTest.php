<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\ManticoreRoadworkSearch;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\EngineManager;
use RomanStruk\ManticoreScoutEngine\Mysql\Builder;
use Tests\TestCase;

class RoadworkFacetValuesTest extends TestCase
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

        $this->index('NDW_FV_1', 'Rijkswaterstaat');
        $this->index('NDW_FV_2', 'Gemeente Utrecht');

        $this->syncToManticore();
    }

    protected function tearDown(): void
    {
        $this->dropIndex();

        parent::tearDown();
    }

    public function test_facet_values_match_a_term_with_counts(): void
    {
        $hits = (new ManticoreRoadworkSearch)->facetValues('road_authority', 'rijks');

        $this->assertContains(['value' => 'Rijkswaterstaat', 'count' => 1], $hits);
        $this->assertNotContains('Gemeente Utrecht', array_column($hits, 'value'));
    }

    public function test_facet_values_caps_at_the_given_limit(): void
    {
        $hits = (new ManticoreRoadworkSearch)->facetValues('road_authority', '', 1);

        $this->assertCount(1, $hits);
    }

    private function index(string $sourceId, string $authority): void
    {
        $point = ['type' => 'Point', 'coordinates' => [5.1, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => []], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            $sourceId,
            ['kind' => 'WORK', 'road_authority' => $authority, 'published' => true],
            $point,
            $doc,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );
    }

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
