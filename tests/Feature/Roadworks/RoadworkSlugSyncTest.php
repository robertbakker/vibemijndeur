<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkSlugSyncTest extends TestCase
{
    use RefreshDatabase;

    private function upsert(string $cause): Roadwork
    {
        $point = ['type' => 'Point', 'coordinates' => [4.3, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => ['causeDescription' => $cause]], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', 'NDW_SYNC_1',
            ['kind' => 'WORK', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true],
            $point, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        return Roadwork::where('source_id', 'NDW_SYNC_1')->firstOrFail();
    }

    public function test_upsert_creates_one_current_slug(): void
    {
        $rw = $this->upsert('GAS Hoofdstraat');

        $current = DB::table('roadwork_slugs')->where('roadwork_id', $rw->id)->where('is_current', true)->first();
        $this->assertNotNull($current);
        $this->assertSame('s-gravenhage-gas-hoofdstraat', $current->slug);
    }

    public function test_title_change_demotes_old_slug_to_redirect(): void
    {
        $rw = $this->upsert('GAS Hoofdstraat');
        $this->upsert('Riolering Vervangen'); // same source_id => update, new title

        $rows = DB::table('roadwork_slugs')->where('roadwork_id', $rw->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('s-gravenhage-gas-hoofdstraat', $rows[0]->slug);
        $this->assertFalse((bool) $rows[0]->is_current);
        $this->assertSame('s-gravenhage-riolering-vervangen', $rows[1]->slug);
        $this->assertTrue((bool) $rows[1]->is_current);
    }

    public function test_unchanged_title_does_not_create_new_slug(): void
    {
        $rw = $this->upsert('GAS Hoofdstraat');
        $this->upsert('GAS Hoofdstraat');

        $this->assertSame(1, DB::table('roadwork_slugs')->where('roadwork_id', $rw->id)->count());
    }

    public function test_backfill_assigns_slugs_and_is_idempotent(): void
    {
        DB::table('roadworks')->insert([
            ['source' => 'X', 'source_id' => 'B1', 'road_authority' => 'Gemeente Venlo',
                'feature' => json_encode(['situation' => ['properties' => ['causeDescription' => 'Brug']]])],
        ]);

        $this->artisan('roadworks:backfill-slugs')->assertSuccessful();
        $this->artisan('roadworks:backfill-slugs')->assertSuccessful();

        $this->assertSame(1, DB::table('roadwork_slugs')->where('slug', 'venlo-brug')->where('is_current', true)->count());
    }
}
