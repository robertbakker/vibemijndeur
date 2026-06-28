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

class SuggestEndpointTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        $this->dropIndex();

        parent::tearDown();
    }

    public function test_it_returns_suggestions_as_json(): void
    {
        $this->index('NDW_EP_1', 'Rijkswaterstaat');
        $this->syncToManticore();

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

    public function test_endpoint_is_rate_limited(): void
    {
        // Blank term short-circuits before the search engine, so this exercises
        // only the throttle:120,1 middleware. The 121st request in the window is 429.
        for ($request = 0; $request < 120; $request++) {
            $this->getJson('/api/suggest?q=')->assertOk();
        }

        $this->getJson('/api/suggest?q=')->assertStatus(429);
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
