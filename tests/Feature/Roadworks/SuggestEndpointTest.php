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

class SuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Roadwork::removeAllFromSearch();

        parent::tearDown();
    }

    public function test_it_returns_suggestions_as_json(): void
    {
        $this->index('NDW_EP_1', 'Rijkswaterstaat');
        $this->reindex(1);

        $this->getJson('/api/suggest?q=rijks')
            ->assertOk()
            ->assertJsonPath('suggestions.0.type', 'road_authority')
            ->assertJsonPath('suggestions.0.label', 'Rijkswaterstaat')
            ->assertJsonPath('suggestions.0.url', '/rijkswaterstaat')
            ->assertJsonPath('suggestions.0.count', 1);
    }

    public function test_blank_term_returns_an_empty_list(): void
    {
        $this->getJson('/api/suggest?q=')
            ->assertOk()
            ->assertExactJson(['suggestions' => []]);
    }

    public function test_limit_over_the_maximum_is_rejected(): void
    {
        $this->getJson('/api/suggest?q=rijks&limit=99')->assertUnprocessable();
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

    private function reindex(int $expected): void
    {
        Roadwork::removeAllFromSearch();
        Roadwork::makeAllSearchable();
        Artisan::call('scout:sync-index-settings');

        $search = app(RoadworkSearch::class);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            try {
                if ((int) ($search->text('')['estimatedTotalHits'] ?? 0) === $expected) {
                    return;
                }
            } catch (\Throwable) {
                // keep waiting
            }
            usleep(200_000);
        }
        $this->fail('Meilisearch did not reflect the expected document count in time.');
    }
}
