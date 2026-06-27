<?php

declare(strict_types=1);

namespace App\Router\Segments;

use App\Data\RoadworkStatus;
use App\Router\ListingQuery;
use App\Router\SegmentCursor;
use App\Router\UrlSegment;

final class StatusSegment implements UrlSegment
{
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segment = $cursor->peek(1)[0] ?? null;
        if ($segment === null) {
            return 0;
        }

        $status = RoadworkStatus::fromSlug($segment);
        if ($status === null) {
            return 0;
        }

        $query->addStatus($status->value);
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $first = $query->statuses()[0] ?? null;

        return $first === null ? null : RoadworkStatus::from($first)->slug();
    }
}
