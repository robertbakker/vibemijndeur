<?php

declare(strict_types=1);

namespace App\Melvin\Data;

use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Data;

/**
 * Validates and extracts the slice of Melvin feature `properties` we actually
 * consume (the promoted columns). Unknown properties are ignored here but kept
 * verbatim in the stored `feature` jsonb — so we never lose data we don't model.
 */
class FeatureProperties extends Data
{
    public const array SITUATION_TYPES = ['SITUATION', 'EXTERNAL_SITUATION'];

    public const array RESTRICTION_TYPES = ['RESTRICTION', 'EXTERNAL_RESTRICTION'];

    public const array DETOUR_TYPES = ['DETOUR', 'EXTERNAL_DETOUR'];

    public function __construct(
        #[In([...self::SITUATION_TYPES, ...self::RESTRICTION_TYPES, ...self::DETOUR_TYPES])]
        public string $type,
        public ?string $source = null,
        public ?string $situationId = null,
        public ?string $status = null,
        public ?string $activityType = null,
        public ?string $name = null,
        public ?bool $published = null,
    ) {
    }

    public function isSituation(): bool
    {
        return in_array($this->type, self::SITUATION_TYPES, true);
    }

    public function isRestriction(): bool
    {
        return in_array($this->type, self::RESTRICTION_TYPES, true);
    }

    public function isDetour(): bool
    {
        return in_array($this->type, self::DETOUR_TYPES, true);
    }
}
