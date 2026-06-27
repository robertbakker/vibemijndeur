<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One autosuggest hit: a facet value linking to a pretty listing URL.
 */
final class Suggestion extends Data
{
    public function __construct(
        public string $type,
        public string $label,
        public string $url,
        public int $count,
    ) {}
}
