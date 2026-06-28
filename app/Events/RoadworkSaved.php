<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a roadwork row is upserted (inserted or updated). Carries the row id so
 * downstream work — currently rebuilding its CBS area links — can act on it.
 */
final readonly class RoadworkSaved
{
    use Dispatchable;

    public function __construct(
        public int $roadworkId,
        public bool $inserted,
    ) {}
}
