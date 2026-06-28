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

        $map = $this->slugToName();
        $resolved = [];
        foreach (explode(',', $segment) as $value) {
            $name = $map[$value] ?? null;
            if ($name === null) {
                return 0;
            }
            $resolved[] = $name;
        }

        foreach ($resolved as $name) {
            $query->addAuthority($name);
        }
        $cursor->consume(1);

        return 1;
    }

    public function build(ListingQuery $query): ?string
    {
        $slugs = array_map(fn (string $name): string => Str::slug($name), $query->authorities());
        if ($slugs === []) {
            return null;
        }
        sort($slugs);

        return implode(',', $slugs);
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
