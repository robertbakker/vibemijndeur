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

    public function test_type_segment_round_trips(): void
    {
        $cursor = new SegmentCursor(['wegdek']);
        $query = new ListingQuery;
        $segment = new TypeSegment;

        $this->assertSame(1, $segment->match($cursor, $query));
        $this->assertSame(['Wegdek'], $query->types());
        $this->assertSame('wegdek', $segment->build($query));
    }
}
