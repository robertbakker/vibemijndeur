<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Router\ListingQuery;
use App\Router\SegmentCursor;
use App\Router\Segments\AreaSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaSegmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedAmsterdam(): Gemeente
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

        return $gemeente;
    }

    public function test_it_resolves_a_bare_gemeente(): void
    {
        $gemeente = $this->seedAmsterdam();
        $cursor = new SegmentCursor(['amsterdam']);
        $query = new ListingQuery;

        $consumed = (new AreaSegment)->match($cursor, $query);

        $this->assertSame(1, $consumed);
        $this->assertSame(['level' => 'gemeente', 'id' => $gemeente->id, 'name' => 'Amsterdam'], $query->area());
    }

    public function test_it_resolves_the_long_form(): void
    {
        $gemeente = $this->seedAmsterdam();
        $cursor = new SegmentCursor(['noord-holland', 'amsterdam']);
        $query = new ListingQuery;

        $consumed = (new AreaSegment)->match($cursor, $query);

        $this->assertSame(2, $consumed);
        $this->assertSame($gemeente->id, $query->area()['id']);
    }

    public function test_it_stops_before_a_non_area_segment(): void
    {
        $this->seedAmsterdam();
        $cursor = new SegmentCursor(['amsterdam', 'gestremd']);
        $query = new ListingQuery;

        $consumed = (new AreaSegment)->match($cursor, $query);

        $this->assertSame(1, $consumed); // 'gestremd' left for the next handler
        $this->assertSame(['gestremd'], $cursor->remaining());
    }

    public function test_it_returns_zero_when_first_segment_is_not_an_area(): void
    {
        $this->seedAmsterdam();
        $cursor = new SegmentCursor(['gestremd']);
        $query = new ListingQuery;

        $this->assertSame(0, (new AreaSegment)->match($cursor, $query));
    }

    public function test_build_emits_canonical_path(): void
    {
        $gemeente = $this->seedAmsterdam();
        $query = new ListingQuery;
        $query->setArea('gemeente', $gemeente->id, 'Amsterdam');

        $this->assertSame('amsterdam', (new AreaSegment)->build($query));
    }
}
