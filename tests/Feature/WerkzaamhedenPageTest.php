<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Roadworks\Contracts\RoadworkSearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WerkzaamhedenPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedAmsterdam(): void
    {
        $province = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $province->id]);
        Slug::factory()->create([
            'slug' => 'amsterdam', 'parent_id' => null,
            'sluggable_type' => $gemeente->getMorphClass(), 'sluggable_id' => $gemeente->id,
        ]);
    }

    public function test_gemeente_facet_options_carry_clean_toggle_urls(): void
    {
        $this->seedAmsterdam();

        $this->mock(RoadworkSearchEngine::class, function ($mock): void {
            $mock->shouldReceive('browse')->andReturn([
                'estimatedTotalHits' => 0,
                'hits' => [],
                'facetDistribution' => [
                    'gemeente' => ['Amsterdam' => 5],
                    'status_key' => ['planned' => 2],
                    'work_type' => [],
                    'provincie' => [],
                    'road_authority' => [],
                ],
            ]);
        });

        $this->get('/werkzaamheden')->assertInertia(
            fn (Assert $page): AssertableInertia => $page
                ->component('Werkzaamheden')
                ->where('facets.gemeente.options.0.key', 'Amsterdam')
                ->where('facets.gemeente.options.0.url', '/amsterdam')
                ->where('facets.gemeente.options.0.checked', false)
        );
    }
}
