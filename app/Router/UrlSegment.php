<?php

declare(strict_types=1);

namespace App\Router;

interface UrlSegment
{
    /**
     * Parse a slice of the path into the query. Returns how many segments were
     * consumed (0 = no match).
     */
    public function match(SegmentCursor $cursor, ListingQuery $query): int;

    /**
     * Emit this handler's canonical path segment(s) for the query, or null.
     */
    public function build(ListingQuery $query): ?string;
}
