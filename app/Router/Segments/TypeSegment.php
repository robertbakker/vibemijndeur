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

        $labels = RoadworkType::labels();
        $resolved = [];
        foreach (explode(',', $segment) as $value) {
            $label = null;
            foreach ($labels as $candidate) {
                if (Str::slug($candidate) === $value) {
                    $label = $candidate;
                    break;
                }
            }
            if ($label === null) {
                return 0;
            }
            $resolved[] = $label;
        }

        foreach ($resolved as $label) {
            $query->addType($label);
        }
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $slugs = array_map(fn (string $label): string => Str::slug($label), $query->types());
        if ($slugs === []) {
            return null;
        }
        sort($slugs);

        return implode(',', $slugs);
    }
}
