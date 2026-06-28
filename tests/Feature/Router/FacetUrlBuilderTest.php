<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Router\FacetUrlBuilder;
use App\Router\ListingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacetUrlBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function seedAmsterdam(): Gemeente
    {
        $province = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $province->id]);
        Slug::factory()->create([
            'slug' => 'amsterdam', 'parent_id' => null,
            'sluggable_type' => $gemeente->getMorphClass(), 'sluggable_id' => $gemeente->id,
        ]);

        return $gemeente;
    }

    public function test_unselected_status_option_url_adds_the_status(): void
    {
        $gemeente = $this->seedAmsterdam();
        $current = new ListingQuery;
        $current->addArea('gemeente', (int) $gemeente->id, 'Amsterdam');

        $options = app(FacetUrlBuilder::class)->options($current, 'status', [
            ['key' => 'planned', 'label' => 'Gepland', 'count' => 3, 'checked' => false, 'dot' => '#2F6BD8'],
        ]);

        $this->assertSame('/amsterdam/gepland', $options[0]->url);
        $this->assertFalse($options[0]->checked);
    }

    public function test_selected_gemeente_option_url_removes_it(): void
    {
        $gemeente = $this->seedAmsterdam();
        $current = new ListingQuery;
        $current->addArea('gemeente', (int) $gemeente->id, 'Amsterdam');
        $current->addStatus('planned');

        $options = app(FacetUrlBuilder::class)->options($current, 'gemeente', [
            ['key' => 'Amsterdam', 'label' => 'Amsterdam', 'count' => 9, 'checked' => true],
        ]);

        // Toggling Amsterdam off leaves only the status segment.
        $this->assertSame('/gepland', $options[0]->url);
        $this->assertTrue($options[0]->checked);
    }
}
