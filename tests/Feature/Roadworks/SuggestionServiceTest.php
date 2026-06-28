<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Roadwork;
use App\Models\Slug;
use App\Models\Wijk;
use App\Roadworks\Contracts\RoadworkSearchEngine;
use App\Roadworks\RoadworkUpserter;
use App\Roadworks\SuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\EngineManager;
use RomanStruk\ManticoreScoutEngine\Mysql\Builder;
use Tests\TestCase;

class SuggestionServiceTest extends TestCase
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

    public function test_authority_suggestion_links_to_its_slug_url(): void
    {
        $this->roadwork('NDW_S_AUTH', ['road_authority' => 'Rijkswaterstaat'], [5.1, 52.0]);
        $this->syncToManticore();

        $suggestions = app(SuggestionService::class)->suggest('rijks');

        $this->assertNotEmpty($suggestions);
        $first = $suggestions[0];
        $this->assertSame('road_authority', $first->type);
        $this->assertSame('Rijkswaterstaat', $first->label);
        $this->assertSame('/rijkswaterstaat', $first->url);
        $this->assertSame(1, $first->count);
    }

    public function test_gemeente_suggestion_uses_the_canonical_url(): void
    {
        $gemeente = $this->gemeente('Amsterdam', 'PV27', 'Noord-Holland', 'POLYGON((0 0,1 0,1 1,0 1,0 0))');
        Slug::factory()->create([
            'slug' => 'amsterdam',
            'sluggable_type' => $gemeente->getMorphClass(),
            'sluggable_id' => $gemeente->id,
            'parent_id' => null,
        ]);
        $this->roadwork('NDW_S_AMS', ['road_authority' => 'X'], [0.5, 0.5]);
        $this->syncToManticore();

        $suggestions = app(SuggestionService::class)->suggest('amst');

        $gemeenteHit = collect($suggestions)->firstWhere('type', 'gemeente');
        $this->assertNotNull($gemeenteHit);
        $this->assertSame('Amsterdam', $gemeenteHit->label);
        $this->assertSame('/amsterdam', $gemeenteHit->url);
        $this->assertNull(
            collect($suggestions)->firstWhere('type', 'buurt'),
            'slug-less buurt should be skipped, not surfaced',
        );
    }

    public function test_ambiguous_name_yields_one_suggestion_per_area(): void
    {
        $nh = $this->gemeente('Bergen', 'PV27', 'Noord-Holland', 'POLYGON((0 0,1 0,1 1,0 1,0 0))');
        $li = $this->gemeente('Bergen', 'PV31', 'Limburg', 'POLYGON((2 0,3 0,3 1,2 1,2 0))');

        $nhProvincieSlug = Slug::factory()->create([
            'slug' => 'noord-holland', 'sluggable_type' => $nh->provincie->getMorphClass(),
            'sluggable_id' => $nh->provincie_id, 'parent_id' => null,
        ]);
        $liProvincieSlug = Slug::factory()->create([
            'slug' => 'limburg', 'sluggable_type' => $li->provincie->getMorphClass(),
            'sluggable_id' => $li->provincie_id, 'parent_id' => null,
        ]);
        Slug::factory()->create([
            'slug' => 'bergen', 'sluggable_type' => $nh->getMorphClass(),
            'sluggable_id' => $nh->id, 'parent_id' => $nhProvincieSlug->id,
        ]);
        Slug::factory()->create([
            'slug' => 'bergen', 'sluggable_type' => $li->getMorphClass(),
            'sluggable_id' => $li->id, 'parent_id' => $liProvincieSlug->id,
        ]);

        $this->roadwork('NDW_S_BNH', ['road_authority' => 'X'], [0.5, 0.5]);
        $this->roadwork('NDW_S_BLI', ['road_authority' => 'X'], [2.5, 0.5]);
        $this->syncToManticore();

        $urls = collect(app(SuggestionService::class)->suggest('bergen'))
            ->where('type', 'gemeente')
            ->pluck('url')
            ->all();
        fwrite(STDERR, "\nURLS: ".json_encode($urls)."\n");

        $this->assertContains('/noord-holland/bergen', $urls);
        $this->assertContains('/limburg/bergen', $urls);
    }

    public function test_exact_match_outranks_a_higher_count_prefix_match(): void
    {
        $this->roadwork('NDW_S_EX', ['road_authority' => 'Bergen'], [5.1, 52.0]);
        $this->roadwork('NDW_S_PF1', ['road_authority' => 'Bergen op Zoom'], [5.1, 52.0]);
        $this->roadwork('NDW_S_PF2', ['road_authority' => 'Bergen op Zoom'], [5.1, 52.0]);
        $this->syncToManticore();

        $labels = collect(app(SuggestionService::class)->suggest('Bergen'))->pluck('label')->all();

        $this->assertSame('Bergen', $labels[0]); // exact (count 1) before prefix 'Bergen op Zoom' (count 2)
    }

    public function test_blank_term_returns_empty(): void
    {
        $this->assertSame([], app(SuggestionService::class)->suggest('   '));
        $this->assertSame([], app(SuggestionService::class)->suggest(null));
    }

    public function test_respects_the_limit(): void
    {
        $this->roadwork('NDW_S_L1', ['road_authority' => 'Provincie Noord-Holland'], [5.1, 52.0]);
        $this->roadwork('NDW_S_L2', ['road_authority' => 'Provincie Noord-Brabant'], [5.1, 52.0]);
        $this->roadwork('NDW_S_L3', ['road_authority' => 'Provincie Noord-Drenthe'], [5.1, 52.0]);
        $this->syncToManticore();

        $this->assertCount(2, app(SuggestionService::class)->suggest('Provincie Noord', 2));
    }

    public function test_search_failure_degrades_to_empty(): void
    {
        $this->mock(RoadworkSearchEngine::class)
            ->shouldReceive('facetValues')
            ->andThrow(new \RuntimeException('manticore down'));

        $this->assertSame([], app(SuggestionService::class)->suggest('amst'));
    }

    /**
     * @param  array<string, mixed>  $promoted
     * @param  array{0: float, 1: float}  $coordinates
     */
    private function roadwork(string $sourceId, array $promoted, array $coordinates): void
    {
        $point = ['type' => 'Point', 'coordinates' => $coordinates];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => []], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            $sourceId,
            ['kind' => 'WORK', 'published' => true, ...$promoted],
            $point,
            $doc,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );
    }

    /**
     * Create a gemeente (in a named provincie) plus a buurt covering the point
     * so the spatial join in `withAdministrativeAreas()` resolves the gemeente
     * name into the indexed document.
     */
    private function gemeente(string $name, string $provincieCode, string $provincieName, string $wkt): Gemeente
    {
        $provincie = Provincie::factory()->create(['code' => $provincieCode, 'name' => $provincieName]);
        $gemeente = Gemeente::factory()->create(['name' => $name, 'provincie_id' => $provincie->id]);
        $wijk = Wijk::factory()->create(['gemeente_id' => $gemeente->id]);

        DB::statement(
            'INSERT INTO buurten (code, name, year, wijk_id, gemeente_id, geometry)
             VALUES (?, ?, 2024, ?, ?, ST_Multi(ST_GeomFromText(?, 4326)))',
            ['BU'.fake()->unique()->numerify('########'), $name, $wijk->id, $gemeente->id, $wkt],
        );

        return $gemeente->fresh(['provincie']);
    }

    /**
     * Mirror every seeded roadwork into the Manticore test index. Manticore's RT
     * index is synchronous, so the documents are searchable immediately.
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
