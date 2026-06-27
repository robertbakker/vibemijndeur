<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;

/**
 * Single source of truth for a roadwork's display title, derived from Melvin's
 * comma-packed `causeDescription`. Used by the detail page, the cards, AND the
 * slug generator so the URL can never drift from the shown title.
 */
final class RoadworkTitle
{
    /**
     * @return list<string>
     */
    public static function parts(Roadwork $roadwork): array
    {
        $raw = data_get($roadwork->feature, 'situation.properties.causeDescription');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    public static function for(Roadwork $roadwork): string
    {
        $parts = self::parts($roadwork);

        if ($parts !== []) {
            return $parts[count($parts) - 1];
        }

        return trim(($roadwork->road_authority ?? 'Wegwerkzaamheden').' – '.($roadwork->kind ?? ''), ' –');
    }
}
