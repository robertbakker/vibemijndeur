<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Roadwork;
use App\Roadworks\RoadworkTitle;
use App\Roadworks\RoadworkType;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A roadwork reshaped for the homepage "in uw buurt" cards and the map list.
 * Carries the design's status palette and a Font Awesome icon so the frontend
 * styles each card straight from the payload.
 */
class RoadworkCard extends Data
{
    public function __construct(
        public int $id,
        public ?string $slug,
        public string $title,
        public string $locationLabel,
        public string $period,
        public string $typeLabel,
        public string $icon,
        public string $statusKey,
        public string $statusLabel,
        public string $markerColor,
        public string $chipBg,
        public string $chipText,
    ) {}

    public static function fromModel(Roadwork $roadwork): self
    {
        $status = RoadworkStatus::for($roadwork);
        $palette = $status->palette();
        $type = RoadworkType::for($roadwork);

        return new self(
            id: $roadwork->id,
            slug: $roadwork->currentSlug?->slug,
            title: RoadworkTitle::for($roadwork),
            locationLabel: $roadwork->road_authority ?? 'Nederland',
            period: self::period($roadwork),
            typeLabel: $type['label'],
            icon: $type['icon'],
            statusKey: $status->value,
            statusLabel: $status->label(),
            markerColor: $palette['markerColor'],
            chipBg: $palette['chipBg'],
            chipText: $palette['chipText'],
        );
    }

    private static function period(Roadwork $roadwork): string
    {
        $start = $roadwork->start_date === null ? null : CarbonImmutable::parse((string) $roadwork->start_date)->translatedFormat('d M Y');
        $end = $roadwork->end_date === null ? null : CarbonImmutable::parse((string) $roadwork->end_date)->translatedFormat('d M Y');

        return match (true) {
            $start !== null && $end !== null => "{$start} – {$end}",
            $start !== null => "Vanaf {$start}",
            $end !== null => "Tot {$end}",
            default => 'Periode onbekend',
        };
    }
}
