<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Roadwork;
use Carbon\CarbonImmutable;

/**
 * The three lifecycle states shown across the UI (cards, map markers, detail
 * banner). Each state carries the design's colour palette so the frontend can
 * style chips, markers and banners without duplicating the mapping.
 *
 * @phpstan-type StatusPalette array{
 *     markerColor: string,
 *     chipBg: string,
 *     chipText: string,
 *     bannerBg: string,
 *     bannerText: string,
 *     ringColor: string
 * }
 */
enum RoadworkStatus: string
{
    case Active = 'active';
    case Planned = 'planned';
    case Done = 'done';

    /**
     * Derive the state from a roadwork's promoted `status` column, falling back
     * to its start/end dates when the source left it blank.
     */
    public static function for(Roadwork $roadwork): self
    {
        return match ($roadwork->status) {
            'running' => self::Active,
            'final' => self::Done,
            default => self::fromDates($roadwork),
        };
    }

    private static function fromDates(Roadwork $roadwork): self
    {
        $start = $roadwork->start_date === null ? null : CarbonImmutable::parse((string) $roadwork->start_date);
        $end = $roadwork->end_date === null ? null : CarbonImmutable::parse((string) $roadwork->end_date);

        return match (true) {
            $start !== null && $start->isFuture() => self::Planned,
            $end !== null && $end->isPast() => self::Done,
            default => self::Active,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'In uitvoering',
            self::Planned => 'Gepland',
            self::Done => 'Afgerond',
        };
    }

    /**
     * @return StatusPalette
     */
    public function palette(): array
    {
        return match ($this) {
            self::Active => [
                'markerColor' => '#FFC400',
                'chipBg' => '#FFF3C2',
                'chipText' => '#7A5B00',
                'bannerBg' => '#FFD200',
                'bannerText' => '#3D5078',
                'ringColor' => 'rgba(11,44,94,.18)',
            ],
            self::Planned => [
                'markerColor' => '#2F6BD8',
                'chipBg' => '#E6EEFB',
                'chipText' => '#173E86',
                'bannerBg' => '#E6EEFB',
                'bannerText' => '#3D5078',
                'ringColor' => 'rgba(47,107,216,.22)',
            ],
            self::Done => [
                'markerColor' => '#1F8A5B',
                'chipBg' => '#E2F1E9',
                'chipText' => '#14633F',
                'bannerBg' => '#E2F1E9',
                'bannerText' => '#3A5A48',
                'ringColor' => 'rgba(31,138,91,.22)',
            ],
        };
    }
}
