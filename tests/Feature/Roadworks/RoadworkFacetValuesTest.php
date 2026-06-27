<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkSearch;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RoadworkFacetValuesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->index('NDW_FV_1', 'Rijkswaterstaat');
        $this->index('NDW_FV_2', 'Gemeente Utrecht');

        Roadwork::removeAllFromSearch();
        Roadwork::makeAllSearchable();
        Artisan::call('scout:sync-index-settings');
        $this->waitForIndex(2);
    }

    protected function tearDown(): void
    {
        Roadwork::removeAllFromSearch();

        parent::tearDown();
    }

    public function test_facet_values_match_a_term_with_counts(): void
    {
        $hits = app(RoadworkSearch::class)->facetValues('road_authority', 'rijks');

        $this->assertContains(['value' => 'Rijkswaterstaat', 'count' => 1], $hits);
        $this->assertNotContains('Gemeente Utrecht', array_column($hits, 'value'));
    }

    public function test_facet_values_caps_at_the_given_limit(): void
    {
        $hits = app(RoadworkSearch::class)->facetValues('road_authority', '', 1);

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
