<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Roadwork;
use App\Models\Slug;
use App\Models\Wijk;
use App\Roadworks\RoadworkSearch;
use App\Roadworks\RoadworkUpserter;
use App\Roadworks\SuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuggestionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Roadwork::removeAllFromSearch();

        parent::tearDown();
    }

    public function test_authority_suggestion_links_to_its_slug_url(): void
    {
        $this->roadwork('NDW_S_AUTH', ['road_authority' => 'Rijkswaterstaat'], [5.1, 52.0]);
        $this->reindex(1);

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
        $this->reindex(1);

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
        $this->reindex(2);

        $urls = collect(app(SuggestionService::class)->suggest('bergen'))
            ->where('type', 'gemeente')
            ->pluck('url')
            ->all();

        $this->assertContains('/noord-holland/bergen', $urls);
        $this->assertContains('/limburg/bergen', $urls);
    }

    public function test_exact_match_outranks_a_higher_count_prefix_match(): void
    {
        $this->roadwork('NDW_S_EX', ['road_authority' => 'Bergen'], [5.1, 52.0]);
        $this->roadwork('NDW_S_PF1', ['road_authority' => 'Bergen op Zoom'], [5.1, 52.0]);
        $this->roadwork('NDW_S_PF2', ['road_authority' => 'Bergen op Zoom'], [5.1, 52.0]);
        $this->reindex(3);

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
        $this->reindex(3);

        $this->assertCount(2, app(SuggestionService::class)->suggest('Provincie Noord', 2));
    }

    public function test_meilisearch_failure_degrades_to_empty(): void
    {
        $this->mock(RoadworkSearch::class)
            ->shouldReceive('facetValues')
            ->andThrow(new \RuntimeException('meili down'));

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
