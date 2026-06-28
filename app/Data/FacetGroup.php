<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * A facet sidebar group (e.g. "Gemeente") with its options.
 */
class FacetGroup extends Data
{
    /**
     * @param  list<FacetOption>  $options
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $options,
    ) {}
}
