<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Router\ListingUrlMapper;
use App\Router\UnmatchedSegmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingUrlMapperTest extends TestCase
{
    use RefreshDatabase;

    private function mapper(): ListingUrlMapper
    {
        return app(ListingUrlMapper::class);
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

    public function test_it_parses_area_and_facets(): void
    {
        $this->seedAmsterdam();
        $query = $this->mapper()->parse('amsterdam/gepland/wegdek');

        $this->assertSame('gemeente', $query->areas()[0]['level']);
        $this->assertSame(['planned'], $query->statuses());
        $this->assertSame(['Wegdek'], $query->types());
    }

    public function test_comma_area_list_is_sorted_in_canonical(): void
    {
        $this->seedAmsterdam();
        $province = Provincie::factory()->create(['name' => 'Utrecht']);
        $utr = Gemeente::factory()->create(['name' => 'Utrecht', 'provincie_id' => $province->id]);
        Slug::factory()->create([
            'slug' => 'utrecht', 'parent_id' => null,
            'sluggable_type' => $utr->getMorphClass(), 'sluggable_id' => $utr->id,
        ]);

        $query = $this->mapper()->parse('utrecht,amsterdam');
        $this->assertSame('/amsterdam,utrecht', $this->mapper()->build($query));
    }

    public function test_round_trip_build_equals_canonical(): void
    {
        $this->seedAmsterdam();
        $mapper = $this->mapper();
        $query = $mapper->parse('noord-holland/amsterdam/gepland');

        // long form parses, but build emits the shortest-unique canonical
        $this->assertSame('/amsterdam/gepland', $mapper->build($query));
    }

    public function test_unmatched_segment_throws(): void
    {
        $this->seedAmsterdam();
        $this->expectException(UnmatchedSegmentException::class);
        $this->mapper()->parse('amsterdam/zwembad');
    }
}
