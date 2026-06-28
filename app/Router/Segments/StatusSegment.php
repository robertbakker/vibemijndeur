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

        $statuses = [];
        foreach (explode(',', $segment) as $value) {
            $status = RoadworkStatus::fromSlug($value);
            if ($status === null) {
                return 0; // whole segment belongs to another handler
            }
            $statuses[] = $status;
        }

        foreach ($statuses as $status) {
            $query->addStatus($status->value);
        }
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $slugs = array_map(
            fn (string $value): string => RoadworkStatus::from($value)->slug(),
            $query->statuses(),
        );
        if ($slugs === []) {
            return null;
        }
        sort($slugs);

        return implode(',', $slugs);
    }
}
