<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Slug;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlugModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_its_sluggable_and_parent_chain(): void
    {
        $province = Slug::factory()->create(['slug' => 'noord-holland', 'parent_id' => null]);
        $gemeente = Gemeente::factory()->create();
        $child = Slug::factory()->create([
            'slug' => 'amsterdam',
            'parent_id' => $province->id,
            'sluggable_type' => $gemeente->getMorphClass(),
            'sluggable_id' => $gemeente->id,
        ]);

        $this->assertSame('noord-holland', $child->parent->slug);
        $this->assertTrue($child->parent->children->contains($child));
        $this->assertTrue($child->sluggable->is($gemeente));
        $this->assertTrue($child->is_current);
    }

    public function test_two_current_siblings_cannot_share_a_slug(): void
    {
        $parent = Slug::factory()->create(['parent_id' => null]);
        Slug::factory()->create(['slug' => 'centrum', 'parent_id' => $parent->id]);

        $this->expectException(QueryException::class);
        Slug::factory()->create(['slug' => 'centrum', 'parent_id' => $parent->id]);
    }
}
