<?php

declare(strict_types=1);

namespace App\Roadworks\Data;

use App\Melvin\Data\Feature;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * The shape stored in `roadworks.feature`: a SITUATION feature plus its related
 * RESTRICTION/DETOUR features, grouped by their shared Melvin id.
 */
class RoadworkDocument extends Data
{
    public function __construct(
        public ?Feature $situation = null,
        /** @var list<Feature> */
        #[DataCollectionOf(Feature::class)]
        public array $restrictions = [],
        /** @var list<Feature> */
        #[DataCollectionOf(Feature::class)]
        public array $detours = [],
        /** @var list<array{url: string, description: ?string}> */
        public array $attachments = [],
    ) {
    }
}
