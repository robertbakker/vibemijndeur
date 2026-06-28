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
        $this->assertSame([['level' => 'gemeente', 'id' => $gemeente->id, 'name' => 'Amsterdam']], $query->areas());
    }

    public function test_it_resolves_the_long_form(): void
    {
        $gemeente = $this->seedAmsterdam();
        $cursor = new SegmentCursor(['noord-holland', 'amsterdam']);
        $query = new ListingQuery;

        $consumed = (new AreaSegment)->match($cursor, $query);

        // Drill-down narrows to the child gemeente only, builds the single segment.
        $this->assertSame(2, $consumed);
        $this->assertSame([['level' => 'gemeente', 'id' => $gemeente->id, 'name' => 'Amsterdam']], $query->areas());
        $this->assertSame('amsterdam', (new AreaSegment)->build($query));
    }

    public function test_it_resolves_a_comma_or_list_of_gemeenten(): void
    {
        $this->seedAmsterdam();
        $ut = Provincie::factory()->create(['name' => 'Utrecht']);
        $utr = Gemeente::factory()->create(['name' => 'Utrecht', 'provincie_id' => $ut->id]);
        Slug::factory()->create([
            'slug' => 'utrecht', 'parent_id' => null,
            'sluggable_type' => $utr->getMorphClass(), 'sluggable_id' => $utr->id,
        ]);

        $cursor = new SegmentCursor(['amsterdam,utrecht']);
        $query = new ListingQuery;

        $this->assertSame(1, (new AreaSegment)->match($cursor, $query));
        $this->assertCount(2, $query->areas());
        $this->assertSame('amsterdam,utrecht', (new AreaSegment)->build($query));
    }

    public function test_it_resolves_a_retired_slug_so_the_controller_can_redirect(): void
    {
        $gemeente = $this->seedAmsterdam();
        Slug::factory()->create([
            'slug' => 'oud-amsterdam', 'parent_id' => null, 'is_current' => false,
            'sluggable_type' => $gemeente->getMorphClass(), 'sluggable_id' => $gemeente->id,
        ]);

        $cursor = new SegmentCursor(['oud-amsterdam']);
        $query = new ListingQuery;

        $this->assertSame(1, (new AreaSegment)->match($cursor, $query));
        // Build emits the current canonical, which differs → controller issues a 301.
        $this->assertSame('amsterdam', (new AreaSegment)->build($query));
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
        $query->addArea('gemeente', $gemeente->id, 'Amsterdam');

        $this->assertSame('amsterdam', (new AreaSegment)->build($query));
    }
}
