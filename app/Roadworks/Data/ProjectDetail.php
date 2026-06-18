<?php

declare(strict_types=1);

namespace App\Roadworks\Data;

use App\Models\Roadwork;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A single roadwork reshaped for the project detail page. Only the fields Melvin
 * actually provides are populated here; the page fills the rest (milestones,
 * contractor, documents, contact) with static placeholder content.
 */
class ProjectDetail extends Data
{
    public function __construct(
        public int $id,
        public string $reference,
        public string $title,
        public string $description,
        public string $statusLabel,
        public string $period,
        public ?string $endLabel,
        public ?string $authority,
        public string $locationLabel,
        public ?float $latitude,
        public ?float $longitude,
    ) {}

    public static function fromModel(Roadwork $roadwork): self
    {
        return new self(
            id: $roadwork->id,
            reference: mb_strtoupper($roadwork->source.'-'.$roadwork->source_id),
            title: self::title($roadwork),
            description: self::description($roadwork),
            statusLabel: self::statusLabel($roadwork),
            period: self::period($roadwork),
            endLabel: $roadwork->end_date === null
                ? null
                : CarbonImmutable::parse((string) $roadwork->end_date)->translatedFormat('d M Y'),
            authority: $roadwork->road_authority,
            locationLabel: $roadwork->road_authority ?? 'Nederland',
            latitude: self::coordinate($roadwork, 'lat'),
            longitude: self::coordinate($roadwork, 'lng'),
        );
    }

    /**
     * @return list<string>
     */
    private static function descriptionParts(Roadwork $roadwork): array
    {
        $raw = data_get($roadwork->feature, 'situation.properties.causeDescription');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }

    private static function title(Roadwork $roadwork): string
    {
        $parts = self::descriptionParts($roadwork);

        if ($parts !== []) {
            return $parts[count($parts) - 1];
        }

        return trim(($roadwork->road_authority ?? 'Wegwerkzaamheden').' – '.($roadwork->kind ?? ''), ' –');
    }

    private static function description(Roadwork $roadwork): string
    {
        $parts = self::descriptionParts($roadwork);

        return $parts !== []
            ? implode(' · ', $parts)
            : 'Voor dit project is nog geen uitgebreide omschrijving beschikbaar.';
    }

    private static function statusLabel(Roadwork $roadwork): string
    {
        return match ($roadwork->status) {
            'running' => 'In uitvoering',
            'final' => 'Afgerond',
            default => self::statusFromDates($roadwork),
        };
    }

    private static function statusFromDates(Roadwork $roadwork): string
    {
        $now = CarbonImmutable::now();
        $start = $roadwork->start_date === null ? null : CarbonImmutable::parse((string) $roadwork->start_date);
        $end = $roadwork->end_date === null ? null : CarbonImmutable::parse((string) $roadwork->end_date);

        if ($start !== null && $start->isFuture()) {
            return 'Gepland';
        }

        if ($end !== null && $end->isPast()) {
            return 'Afgerond';
        }

        return 'In uitvoering';
    }

    private static function period(Roadwork $roadwork): string
    {
        $start = $roadwork->start_date === null ? null : CarbonImmutable::parse((string) $roadwork->start_date)->translatedFormat('F Y');
        $end = $roadwork->end_date === null ? null : CarbonImmutable::parse((string) $roadwork->end_date)->translatedFormat('F Y');

        return match (true) {
            $start !== null && $end !== null => "{$start} — {$end}",
            $start !== null => "Vanaf {$start}",
            $end !== null => "Tot {$end}",
            default => 'Onbekend',
        };
    }

    /**
     * Representative point eagerly selected by {@see Roadwork::scopeWithRepresentativePoint()}.
     */
    private static function coordinate(Roadwork $roadwork, string $axis): ?float
    {
        $value = $roadwork->getAttribute($axis === 'lat' ? 'geo_lat' : 'geo_lng');

        return $value === null ? null : (float) $value;
    }
}
