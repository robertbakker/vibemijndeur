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

        // Seed candidates: current slugs matching seg0 whose morph type may start a path.
        $candidates = Slug::query()
            ->where('slug', $segments[0])
            ->where('is_current', true)
            ->whereIn('sluggable_type', $rootMorphs)
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $consumed = 1;
        $resolved = $candidates;

        // Narrow by structural-child steps for each following segment.
        for ($i = 1; $i < count($segments); $i++) {
            $next = Slug::query()
                ->where('slug', $segments[$i])
                ->where('is_current', true)
                ->whereIn('parent_id', $resolved->pluck('id'))
                ->get();

            if ($next->isEmpty()) {
                break; // belongs to the next handler
            }

            $resolved = $next;
            $consumed++;
        }

        if ($resolved->count() !== 1) {
            return 0; // ambiguous → let the controller 404
        }

        /** @var Slug $slug */
        $slug = $resolved->first();
        $area = $slug->sluggable;
        $query->setArea($this->levelFor($slug), (int) $area->getKey(), (string) $area->name);

        $cursor->consume($consumed);

        return $consumed;
    }

    public function build(ListingQuery $query): ?string
    {
        $area = $query->area();
        if ($area === null) {
            return null;
        }

        $slug = Slug::query()
            ->where('sluggable_type', $this->morphForLevel($area['level']))
            ->where('sluggable_id', $area['id'])
            ->where('is_current', true)
            ->firstOrFail();

        return CanonicalPath::for($slug);
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
