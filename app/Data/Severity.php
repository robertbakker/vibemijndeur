<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Roadwork;

/**
 * Melvin's `severity` column mapped to a Dutch label for the detail page.
 * Anything the source didn't classify falls back to {@see self::Unknown}.
 */
enum Severity: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Highest = 'highest';
    case Unknown = 'unknown';

    public static function for(Roadwork $roadwork): self
    {
        return self::tryFrom((string) $roadwork->severity) ?? self::Unknown;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'Geen',
            self::Low => 'Laag',
            self::Medium => 'Gemiddeld',
            self::High => 'Hoog',
            self::Highest => 'Zeer hoog',
            self::Unknown => 'Onbekend',
        };
    }
}
