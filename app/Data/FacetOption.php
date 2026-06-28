<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * One facet sidebar option: its label, document count, whether it's currently
 * selected, and the clean URL to navigate to when toggled.
 */
class FacetOption extends Data
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public bool $checked,
        public string $url,
        public ?string $dot = null,
    ) {}
}
