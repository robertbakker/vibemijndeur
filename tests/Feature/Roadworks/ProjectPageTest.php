<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProjectPageTest extends TestCase
{
    use RefreshDatabase;

    private function upsert(string $sourceId, string $cause): Roadwork
    {
        $line = ['type' => 'LineString', 'coordinates' => [[4.89, 52.37], [4.90, 52.37]]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $line, 'properties' => ['causeDescription' => $cause]], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', $sourceId,
            ['kind' => 'WORK', 'severity' => 'high', 'status' => 'running', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true, 'start_date' => '2026-07-01T00:00:00Z', 'end_date' => '2026-09-01T00:00:00Z'],
            $line, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        return Roadwork::where('source_id', $sourceId)->firstOrFail();
    }

    public function test_current_slug_renders_project(): void
    {
        $this->upsert('NDW_PAGE_1', 'Kabels / Leidingen, , GAS Hoofdstraat');

        $this->get('/s-gravenhage-gas-hoofdstraat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Projecten/Show')
                ->where('project.title', 'GAS Hoofdstraat')
                ->where('project.description', 'Kabels / Leidingen, GAS Hoofdstraat.')
                ->where('project.authority', "Gemeente 's-Gravenhage")
                ->where('project.severityLabel', 'Hoog')
                ->where('project.hindranceLabel', 'Hinder onbekend')
                ->where('project.locationLabel', "Gemeente 's-Gravenhage")
                ->where('project.slug', 's-gravenhage-gas-hoofdstraat')
                ->whereNot('project.latitude', null)
                ->whereNot('project.longitude', null)
            );
    }

    public function test_historical_slug_redirects_to_current(): void
    {
        $this->upsert('NDW_PAGE_1', 'GAS Hoofdstraat');
        $this->upsert('NDW_PAGE_1', 'Riolering Vervangen');

        $this->get('/s-gravenhage-gas-hoofdstraat')
            ->assertRedirect('/s-gravenhage-riolering-vervangen')
            ->assertStatus(301);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->get('/this-slug-does-not-exist')->assertNotFound();
    }

    public function test_legacy_numeric_url_redirects_to_slug(): void
    {
        $rw = $this->upsert('NDW_PAGE_1', 'GAS Hoofdstraat');

        $this->get("/projecten/{$rw->id}")
            ->assertRedirect('/s-gravenhage-gas-hoofdstraat')
            ->assertStatus(301);
    }

    public function test_named_routes_still_resolve(): void
    {
        $this->get('/kaart')->assertOk();
    }
}
