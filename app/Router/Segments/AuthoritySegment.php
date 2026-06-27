<?php

declare(strict_types=1);

namespace App\Router\Segments;

use App\Models\Roadwork;
use App\Router\ListingQuery;
use App\Router\SegmentCursor;
use App\Router\UrlSegment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AuthoritySegment implements UrlSegment
{
    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segment = $cursor->peek(1)[0] ?? null;
        if ($segment === null) {
            return 0;
        }

        $name = $this->slugToName()[$segment] ?? null;
        if ($name === null) {
            return 0;
        }

        $query->addAuthority($name);
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $first = $query->authorities()[0] ?? null;

        return $first === null ? null : Str::slug($first);
    }

    /**
     * Map of slug => canonical authority name, cached (distinct values rarely change).
     *
     * @return array<string, string>
     */
    private function slugToName(): array
    {
        return Cache::remember('router:authorities', now()->addHour(), function (): array {
            $names = Roadwork::query()
                ->whereNotNull('road_authority')
                ->distinct()
                ->pluck('road_authority');

            $map = [];
            foreach ($names as $name) {
                $map[Str::slug((string) $name)] = (string) $name;
            }

            return $map;
        });
    }
}
