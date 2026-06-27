<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the SEO slug `{municipality}-{title}` for a roadwork and guarantees it
 * is unique across {@see roadwork_slugs}. A roadwork keeping one of its own
 * existing slugs is never treated as a collision.
 */
final class RoadworkSlugger
{
    public function base(Roadwork $roadwork): string
    {
        $authority = $roadwork->road_authority;
        $municipality = $authority === null || trim($authority) === ''
            ? 'nederland'
            : (string) preg_replace('/^(Gemeente|Provincie|Waterschap|Rijkswaterstaat)\s+/i', '', trim($authority));

        $slug = Str::slug(trim($municipality.' '.RoadworkTitle::for($roadwork)));

        return $slug === '' ? 'nederland' : $slug;
    }

    public function unique(string $base, int $roadworkId): string
    {
        $candidate = $base;
        $suffix = 1;

        while ($this->takenByOther($candidate, $roadworkId)) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    private function takenByOther(string $slug, int $roadworkId): bool
    {
        return DB::table('roadwork_slugs')
            ->where('slug', $slug)
            ->where('roadwork_id', '!=', $roadworkId)
            ->exists();
    }
}
