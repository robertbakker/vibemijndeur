<?php

declare(strict_types=1);

namespace App\Melvin\Data;

use Spatie\LaravelData\Data;

/**
 * A GeoJSON Feature (RFC 7946).
 *
 * `geometry` and `properties` are kept as raw arrays — Melvin returns a wide
 * range of property/geometry shapes that we don't model further here.
 */
class Feature extends Data
{
    public function __construct(
        /** Top-level GeoJSON id; Melvin uses it as the situation id. */
        public string|int|null $id = null,
        /** @var array<string, mixed>|null */
        public ?array $geometry = null,
        /** @var array<string, mixed> */
        public array $properties = [],
        public string $type = 'Feature',
    ) {}
}
