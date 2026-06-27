<?php

declare(strict_types=1);

namespace App\Router\Segments;

use App\Roadworks\RoadworkType;
use App\Router\ListingQuery;
use App\Router\SegmentCursor;
use App\Router\UrlSegment;
use Illuminate\Support\Str;

final class TypeSegment implements UrlSegment
{
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segment = $cursor->peek(1)[0] ?? null;
        if ($segment === null) {
            return 0;
        }

        foreach (RoadworkType::labels() as $label) {
            if (Str::slug($label) === $segment) {
                $query->addType($label);
                $cursor->consume(1);

                return 1;
            }
        }

        return 0;
    }

    public function build(ListingQuery $query): ?string
    {
        $first = $query->types()[0] ?? null;

        return $first === null ? null : Str::slug($first);
    }
}
