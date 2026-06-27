<?php

declare(strict_types=1);

namespace App\Router;

final class ListingUrlMapper
{
    /** @var array<string, string> memoized build output by ListingQuery cache key */
    private array $buildCache = [];

    /**
     * @param  list<UrlSegment>  $segments  listing handlers (no RoadworkSegment)
     * @param  list<class-string>  $buildOrder  canonical emit order
     */
    public function __construct(
        private readonly array $segments,
        private readonly array $buildOrder,
    ) {}

    public function parse(string $path): ListingQuery
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn (string $p): bool => $p !== ''));
        $cursor = new SegmentCursor($parts);
        $query = new ListingQuery;

        while (! $cursor->done()) {
            $before = $cursor->position();
            foreach ($this->segments as $segment) {
                if ($segment->match($cursor, $query) > 0) {
                    break;
                }
            }
            if ($cursor->position() === $before) {
                throw new UnmatchedSegmentException($cursor->remaining()[0] ?? '');
            }
        }

        return $query;
    }

    public function build(ListingQuery $query): string
    {
        $key = $query->cacheKey();
        if (isset($this->buildCache[$key])) {
            return $this->buildCache[$key];
        }

        $out = [];
        foreach ($this->buildOrder as $class) {
            foreach ($this->segments as $segment) {
                if ($segment instanceof $class) {
                    $built = $segment->build($query);
                    if ($built !== null && $built !== '') {
                        $out[] = $built;
                    }
                }
            }
        }

        return $this->buildCache[$key] = '/'.implode('/', $out);
    }
}
