<?php

declare(strict_types=1);

namespace App\Router\Segments;

use App\Models\Roadwork;
use App\Models\Slug;
use App\Router\ListingQuery;
use App\Router\RoadworkResolution;
use App\Router\SegmentCursor;
use App\Router\UrlSegment;

final class RoadworkSegment implements UrlSegment
{
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        return 0; // detail is resolved by the controller via resolve(), not folded into the query
    }

    public function build(ListingQuery $query): ?string
    {
        return null;
    }

    /**
     * Resolve a standalone roadwork detail from the full remaining path
     * (single segment). Returns null when no slug matches.
     */
    public function resolve(SegmentCursor $cursor): ?RoadworkResolution
    {
        $remaining = $cursor->remaining();
        if (count($remaining) !== 1) {
            return null;
        }

        $morph = (new Roadwork)->getMorphClass();
        $slug = Slug::query()
            ->where('slug', $remaining[0])
            ->where('sluggable_type', $morph)
            ->first();

        if ($slug === null) {
            return null;
        }

        if (! $slug->is_current) {
            $current = Slug::query()
                ->where('sluggable_type', $morph)
                ->where('sluggable_id', $slug->sluggable_id)
                ->where('is_current', true)
                ->firstOrFail();

            return new RoadworkResolution((int) $slug->sluggable_id, $current->slug);
        }

        return new RoadworkResolution((int) $slug->sluggable_id);
    }
}
