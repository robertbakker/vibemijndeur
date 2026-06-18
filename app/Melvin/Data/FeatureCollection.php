<?php

declare(strict_types=1);

namespace App\Melvin\Data;

use Spatie\LaravelData\Data;

/**
 * A GeoJSON FeatureCollection (RFC 7946).
 */
class FeatureCollection extends Data
{
    public function __construct(
        /** @var array<int, Feature> */
        public array $features = [],
        public string $type = 'FeatureCollection',
    ) {}
}
