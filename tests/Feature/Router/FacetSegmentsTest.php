<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Router\ListingQuery;
use App\Router\SegmentCursor;
use App\Router\Segments\StatusSegment;
use App\Router\Segments\TypeSegment;
use Tests\TestCase;

class FacetSegmentsTest extends TestCase
{
    public function test_status_segment_round_trips(): void
    {
        $cursor = new SegmentCursor(['gepland']);
        $query = new ListingQuery;
        $segment = new StatusSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['planned'], $query->statuses());
        $this->assertSame('gepland', $segment->build($query));
    }

    public function test_status_segment_ignores_unknown(): void
    {
        $cursor = new SegmentCursor(['banaan']);
        $this->assertSame(0, (new StatusSegment)->match($cursor, new ListingQuery));
    }

    public function test_status_segment_parses_and_builds_comma_list(): void
    {
        $query = new ListingQuery;
        $cursor = new SegmentCursor(['afgerond,gepland']);
        $segment = new StatusSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['done', 'planned'], $query->statuses());
        // build is sorted by slug: afgerond < gepland
        $this->assertSame('afgerond,gepland', $segment->build($query));
    }

    public function test_status_segment_rejects_segment_with_unknown_value(): void
    {
        $query = new ListingQuery;
        $cursor = new SegmentCursor(['gepland,zwembad']);
        $segment = new StatusSegment;

        $this->assertSame(0, $segment->match($cursor, $query));
        $this->assertSame([], $query->statuses());
    }

    public function test_type_segment_round_trips(): void
    {
        $cursor = new SegmentCursor(['wegdek']);
        $query = new ListingQuery;
        $segment = new TypeSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['Wegdek'], $query->types());
        $this->assertSame('wegdek', $segment->build($query));
    }

    public function test_type_segment_parses_and_builds_comma_list(): void
    {
        $query = new ListingQuery;
        $cursor = new SegmentCursor(['wegdek,riool']);
        $segment = new TypeSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['Wegdek', 'Riool'], $query->types());
        // sorted by slug: riool < wegdek
        $this->assertSame('riool,wegdek', $segment->build($query));
    }
}
