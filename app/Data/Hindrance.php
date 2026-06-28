<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Roadwork;

/**
 * Melvin's DATEX hindrance classes (`hindranceClass0`–`hindranceClass4`),
 * mapped to a human label and a 0–4 level the detail page uses to phrase the
 * bereikbaarheid copy. Rows with no class fall back to {@see self::Unknown}.
 */
enum Hindrance: string
{
    case None = 'hindranceClass0';
    case Limited = 'hindranceClass1';
    case Moderate = 'hindranceClass2';
    case Severe = 'hindranceClass3';
    case Extreme = 'hindranceClass4';
    case Unknown = 'unknown';

    public static function for(Roadwork $roadwork): self
    {
        return self::tryFrom((string) $roadwork->hindrance) ?? self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Geen hinder',
            self::Limited => 'Beperkte hinder',
            self::Moderate => 'Matige hinder',
            self::Severe => 'Ernstige hinder',
            self::Extreme => 'Zeer ernstige hinder',
            self::Unknown => 'Hinder onbekend',
        };
    }

    /**
     * 0 (none) → 4 (extreme); -1 when the source left the class blank.
     */
    public function level(): int
    {
        return match ($this) {
            self::None => 0,
            self::Limited => 1,
            self::Moderate => 2,
            self::Severe => 3,
            self::Extreme => 4,
            self::Unknown => -1,
        };
    }
}
