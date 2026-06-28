<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\ManticoreRoadworkSearch;
use Laravel\Scout\EngineManager;
use RomanStruk\ManticoreScoutEngine\Mysql\Builder;
use Tests\TestCase;

/**
 * Exercises the Manticore search engine directly against a disposable
 * `testing_roadworks` index seeded with a handful of documents (no Postgres,
 * no Scout). Skips when Manticore is unreachable so the wider suite still runs.
 */
class ManticoreRoadworkSearchTest extends TestCase
{
    private const string INDEX = 'testing_roadworks';

    private ManticoreRoadworkSearch $search;

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

        $this->seedFixtures();

        $this->search = new ManticoreRoadworkSearch;
    }

    protected function tearDown(): void
    {
        $this->dropIndex();

        parent::tearDown();
    }

    public function test_browse_returns_total_and_facet_distribution(): void
    {
        $result = $this->search->browse('', [], [], 0, 0, ['gemeente']);

        $this->assertSame(3, $result['estimatedTotalHits']);
        $this->assertSame(['Amsterdam' => 1, 'Utrecht' => 2], $this->sortedByKey($result['facetDistribution']['gemeente']));
    }

    public function test_browse_facets_respect_filters(): void
    {
        $result = $this->search->browse('', ['status' => 'running'], [], 0, 24, ['gemeente']);

        $this->assertSame(2, $result['estimatedTotalHits']);
        $this->assertEqualsCanonicalizing([1, 3], array_column($result['hits'], 'id'));
        $this->assertSame(['Amsterdam' => 1, 'Utrecht' => 1], $this->sortedByKey($result['facetDistribution']['gemeente']));
    }

    public function test_facet_values_match_a_term_with_counts(): void
    {
        $hits = $this->search->facetValues('gemeente', 'utr');

        $this->assertSame([['value' => 'Utrecht', 'count' => 2]], $hits);
    }

    public function test_bounding_box_excludes_points_outside_and_facets_the_rest(): void
    {
        $result = $this->search->withinBoundingBox('', 52.15, 5.0, 52.0, 5.3, ['status']);

        $this->assertSame(2, $result['estimatedTotalHits']);
        $this->assertEqualsCanonicalizing([1, 2], array_column($result['hits'], 'id'));
        $this->assertSame(['published' => 1, 'running' => 1], $this->sortedByKey($result['facetDistribution']['status']));
        // Geo is rebuilt to the Meili `_geo` shape with float precision intact.
        $hit = collect($result['hits'])->firstWhere('id', 1);
        $this->assertEqualsWithDelta(52.0907, $hit['_geo']['lat'], 0.001);
        $this->assertEqualsWithDelta(5.1214, $hit['_geo']['lng'], 0.001);
    }

    public function test_nearby_filters_by_radius_and_facets_within_it(): void
    {
        $result = $this->search->nearby('', 52.0907, 5.1214, 5000, ['status']);

        $this->assertSame(2, $result['estimatedTotalHits']);
        $this->assertEqualsCanonicalizing([1, 2], array_column($result['hits'], 'id'));
        $this->assertSame(['published' => 1, 'running' => 1], $this->sortedByKey($result['facetDistribution']['status']));
    }

    public function test_text_search_matches_the_description(): void
    {
        $result = $this->search->text('kermis');

        $this->assertSame([1], array_column($result['hits'], 'id'));
    }

    /**
     * Facet counts come back in count order; tied counts are unordered, so sort
     * by key for a stable comparison.
     *
     * @param  array<string, int>  $distribution
     * @return array<string, int>
     */
    private function sortedByKey(array $distribution): array
    {
        ksort($distribution);

        return $distribution;
    }

    private function seedFixtures(): void
    {
        // id, gemeente, status, road_authority, description, lat, lng
        $this->replace(1, 'Utrecht', 'running', 'Gemeente Utrecht', 'Utrecht wegwerkzaamheden kermis', 52.0907, 5.1214);
        $this->replace(2, 'Utrecht', 'published', 'Rijkswaterstaat', 'Utrecht groot onderhoud', 52.0920, 5.1180);
        $this->replace(3, 'Amsterdam', 'running', 'Gemeente Amsterdam', 'Amsterdam brugrenovatie', 52.3702, 4.8952);
    }

    private function replace(int $id, string $gemeente, string $status, string $authority, string $description, float $lat, float $lng): void
    {
        app(Builder::class)->index(self::INDEX)->replace([
            'description' => $description,
            'status' => $status,
            'status_key' => $status,
            'road_authority' => $authority,
            'gemeente' => $gemeente,
            'published' => 1,
            'lat' => sprintf('%.7f', $lat),
            'lng' => sprintf('%.7f', $lng),
            'geometry' => '[]',
        ], $id);
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
