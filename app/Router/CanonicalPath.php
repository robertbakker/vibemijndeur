<?php

declare(strict_types=1);

namespace App\Router;

use App\Models\Gemeente;
use App\Models\Slug;
use Illuminate\Support\Collection;

final class CanonicalPath
{
    public static function for(Slug $slug): string
    {
        $segments = [$slug->slug];
        $cursor = $slug;

        while (! self::isUnique($segments)) {
            $cursor = $cursor->parent;
            if ($cursor === null) {
                break;
            }
            array_unshift($segments, $cursor->slug);

            if ($cursor->sluggable_type === (new Gemeente)->getMorphClass()) {
                break; // promotion cap: never shorten below gemeente
            }
        }

        return implode('/', $segments);
    }

    /**
     * A tail is unique when exactly one current slug has this leaf and its
     * ancestor chain ends with the accumulated prefix.
     *
     * @param  list<string>  $segments
     */
    private static function isUnique(array $segments): bool
    {
        $leaf = $segments[count($segments) - 1];

        /** @var Collection<int, Slug> $candidates */
        $candidates = Slug::query()
            ->where('slug', $leaf)
            ->where('is_current', true)
            ->with('parent.parent.parent')
            ->get();

        $matches = $candidates->filter(
            fn (Slug $candidate): bool => self::tailMatches($candidate, $segments)
        );

        return $matches->count() === 1;
    }

    /** @param list<string> $segments */
    private static function tailMatches(Slug $slug, array $segments): bool
    {
        $cursor = $slug;
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if ($cursor === null || $cursor->slug !== $segments[$i]) {
                return false;
            }
            $cursor = $cursor->parent;
        }

        return true;
    }
}
