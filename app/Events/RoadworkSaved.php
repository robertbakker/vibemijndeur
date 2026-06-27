<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a roadwork row is upserted (inserted or updated). Carries the row id so
 * downstream work — currently rebuilding its CBS area links — can act on it.
 */
final class RoadworkSaved
{
    use Dispatchable;

    public function __construct(
        public readonly int $roadworkId,
        public readonly bool $inserted,
    ) {}
}
