<?php

declare(strict_types=1);

namespace App\Router;

final class SegmentCursor
{
    private int $pos = 0;

    /** @param list<string> $segments */
    public function __construct(private readonly array $segments) {}

    /** @return list<string> */
    public function peek(int $n = 1): array
    {
        return array_values(array_slice($this->segments, $this->pos, $n));
    }

    /** @return list<string> */
    public function remaining(): array
    {
        return array_values(array_slice($this->segments, $this->pos));
    }

    public function consume(int $n): void
    {
        $this->pos += $n;
    }

    public function done(): bool
    {
        return $this->pos >= count($this->segments);
    }

    public function isFirst(): bool
    {
        return $this->pos === 0;
    }

    public function position(): int
    {
        return $this->pos;
    }
}
