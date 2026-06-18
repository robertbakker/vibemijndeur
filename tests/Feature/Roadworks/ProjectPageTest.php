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

    public function test_project_page_renders_real_roadwork_data(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [[4.89, 52.37], [4.90, 52.37]]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $line, 'properties' => ['causeDescription' => 'Kabels / Leidingen, , GAS Hoofdstraat']], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            'NDW_PAGE_1',
            ['kind' => 'WORK', 'severity' => 'high', 'status' => 'running', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true, 'start_date' => '2026-07-01T00:00:00Z', 'end_date' => '2026-09-01T00:00:00Z'],
            $line,
            $doc,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        $roadwork = Roadwork::where('source_id', 'NDW_PAGE_1')->firstOrFail();

        $this->get("/projecten/{$roadwork->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Projecten/Show')
                ->where('project.id', $roadwork->id)
                ->where('project.title', 'GAS Hoofdstraat')
                ->where('project.reference', 'DATEX-NDW_PAGE_1')
                ->where('project.statusLabel', 'In uitvoering')
                ->where('project.authority', "Gemeente 's-Gravenhage")
                ->whereNot('project.latitude', null)
                ->whereNot('project.longitude', null)
            );
    }

    public function test_missing_roadwork_returns_404(): void
    {
        $this->get('/projecten/999999999')->assertNotFound();
    }

    public function test_non_numeric_id_is_not_matched(): void
    {
        $this->get('/projecten/not-a-number')->assertNotFound();
    }
}
