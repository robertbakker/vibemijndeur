<?php

declare(strict_types=1);

namespace App\Router\Segments;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Landsdeel;
use App\Models\Provincie;
use App\Models\Slug;
use App\Models\Wijk;
use App\Router\CanonicalPath;
use App\Router\ListingQuery;
use App\Router\SegmentCursor;
use App\Router\UrlSegment;
use Illuminate\Support\Collection;

final class AreaSegment implements UrlSegment
{
    /** @var list<class-string> morph classes allowed as the first path segment */
    public const ROOT_TYPES = [Landsdeel::class, Provincie::class, Gemeente::class];

    public function match(SegmentCursor $cursor, ListingQuery $query): int
    {
        $segments = $cursor->remaining();
        if ($segments === []) {
            return 0;
        }

        $rootMorphs = array_map(fn (string $c): string => (new $c)->getMorphClass(), self::ROOT_TYPES);

        // Segment 0: comma OR-list of current (or retired, for redirects) root-type slugs.
        $resolved = $this->resolveRootSegment($segments[0], $rootMorphs);
        if ($resolved->isEmpty()) {
            return 0;
        }

        $consumed = 1;

        // Following segments: drill into children while every comma value
        // resolves as a child of the current resolved set.
        for ($i = 1; $i < count($segments); $i++) {
            $children = $this->resolveChildSegment($segments[$i], $resolved->pluck('id')->all());
            if ($children === null) {
                break; // belongs to a status/type/authority handler
            }
            $resolved = $children;
            $consumed++;
        }

        foreach ($resolved as $slug) {
            $area = $slug->sluggable;
            $query->addArea($this->levelFor($slug), (int) $area->getKey(), (string) $area->name);
        }
        $cursor->consume($consumed);

        return $consumed;
    }

    /**
     * @param  list<string>  $rootMorphs
     * @return Collection<int, Slug>
     */
    private function resolveRootSegment(string $segment, array $rootMorphs): Collection
    {
        $slugs = collect();
        foreach (explode(',', $segment) as $value) {
            $slug = Slug::query()
                ->where('slug', $value)
                ->where('is_current', true)
                ->whereIn('sluggable_type', $rootMorphs)
                ->first()
                // Fall back to a retired slug so stale URLs still resolve; the
                // controller rebuilds the current canonical and 301-redirects.
                ?? Slug::query()
                    ->where('slug', $value)
                    ->where('is_current', false)
                    ->whereIn('sluggable_type', $rootMorphs)
                    ->first();

            if ($slug === null) {
                return collect(); // any unresolved value disqualifies the segment as area
            }
            $slugs->push($slug);
        }

        return $slugs;
    }

    /**
     * @param  list<int>  $parentIds
     * @return Collection<int, Slug>|null null when the segment is not a child-area segment
     */
    private function resolveChildSegment(string $segment, array $parentIds): ?Collection
    {
        $slugs = collect();
        foreach (explode(',', $segment) as $value) {
            $slug = Slug::query()
                ->where('slug', $value)
                ->where('is_current', true)
                ->whereIn('parent_id', $parentIds)
                ->first();
            if ($slug === null) {
                return null;
            }
            $slugs->push($slug);
        }

        return $slugs;
    }

    public function build(ListingQuery $query): ?string
    {
        $areas = $query->areas();
        if ($areas === []) {
            return null;
        }

        $slugs = [];
        foreach ($areas as $area) {
            $slug = Slug::query()
                ->where('sluggable_type', $this->morphForLevel($area['level']))
                ->where('sluggable_id', $area['id'])
                ->where('is_current', true)
                ->firstOrFail();
            $slugs[] = CanonicalPath::for($slug);
        }
        sort($slugs);

        return implode(',', $slugs);
    }

    public function levelFor(Slug $slug): string
    {
        return match ($slug->sluggable_type) {
            (new Provincie)->getMorphClass() => 'provincie',
            (new Gemeente)->getMorphClass() => 'gemeente',
            (new Wijk)->getMorphClass() => 'wijk',
            (new Buurt)->getMorphClass() => 'buurt',
            default => 'provincie',
        };
    }

    private function morphForLevel(string $level): string
    {
        return match ($level) {
            'provincie' => (new Provincie)->getMorphClass(),
            'gemeente' => (new Gemeente)->getMorphClass(),
            'wijk' => (new Wijk)->getMorphClass(),
            'buurt' => (new Buurt)->getMorphClass(),
            default => (new Provincie)->getMorphClass(),
        };
    }
}
