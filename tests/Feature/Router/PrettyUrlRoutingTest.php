<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Roadwork;
use App\Models\Slug;
use App\Roadworks\Contracts\RoadworkSearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrettyUrlRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid hitting live search: the listing render just needs an empty result set.
        $this->mock(RoadworkSearchEngine::class, function ($mock): void {
            $mock->shouldReceive('browse')->andReturn(['estimatedTotalHits' => 0, 'hits' => []]);
        });
    }

    private function seedAmsterdam(): void
    {
        $province = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $province->id]);
        $nh = Slug::factory()->create([
            'slug' => 'noord-holland', 'parent_id' => null,
            'sluggable_type' => $province->getMorphClass(), 'sluggable_id' => $province->id,
        ]);
        Slug::factory()->create([
            'slug' => 'amsterdam', 'parent_id' => $nh->id,
            'sluggable_type' => $gemeente->getMorphClass(), 'sluggable_id' => $gemeente->id,
        ]);
    }

    public function test_bare_gemeente_listing_renders(): void
    {
        $this->seedAmsterdam();
        $this->get('/amsterdam')->assertOk();
    }

    public function test_long_form_redirects_to_canonical(): void
    {
        $this->seedAmsterdam();
        $this->get('/noord-holland/amsterdam')
            ->assertStatus(301)
            ->assertRedirect('/amsterdam');
    }

    public function test_roadwork_detail_resolves(): void
    {
        $id = DB::table('roadworks')->insertGetId(
            ['source' => 'X', 'source_id' => 'A1', 'feature' => '{}'], 'id'
        );
        Slug::factory()->create([
            'slug' => 'a4-knooppunt-x', 'parent_id' => null, 'is_current' => true,
            'sluggable_type' => (new Roadwork)->getMorphClass(), 'sluggable_id' => $id,
        ]);

        $this->get('/a4-knooppunt-x')->assertOk();
    }

    public function test_unknown_path_404s(): void
    {
        $this->get('/totally-unknown')->assertNotFound();
    }
}
