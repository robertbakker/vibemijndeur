<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Roadwork;
use App\Roadworks\RoadworkTitle;
use App\Roadworks\RoadworkType;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A single roadwork reshaped for the project detail page. Fields Melvin
 * provides (title, dates, authority, location) are populated from the model;
 * the accessibility list uses generic, status-aware copy where the source has
 * no structured bereikbaarheid data.
 *
 * @phpstan-type AccessItem array{icon: string, title: string, text: string}
 */
class ProjectDetail extends Data
{
    /**
     * @param  list<AccessItem>  $access
     */
    public function __construct(
        public int $id,
        public ?string $slug,
        public string $reference,
        public string $title,
        public string $description,
        public string $typeLabel,
        public string $icon,
        public string $statusKey,
        public string $statusLabel,
        public string $period,
        public string $startLabel,
        public string $endLabel,
        public string $duration,
        public string $phaseLabel,
        public int $progress,
        public ?string $authority,
        public string $locationLabel,
        public ?float $latitude,
        public ?float $longitude,
        public string $markerColor,
        public string $chipBg,
        public string $chipText,
        public string $bannerBg,
        public string $bannerText,
        public string $ringColor,
        public array $access,
        public string $contact,
    ) {}

    public static function fromModel(Roadwork $roadwork): self
    {
        $status = RoadworkStatus::for($roadwork);
        $palette = $status->palette();
        $type = RoadworkType::for($roadwork);

        return new self(
            id: $roadwork->id,
            slug: $roadwork->currentSlug?->slug,
            reference: mb_strtoupper($roadwork->source.'-'.$roadwork->source_id),
            title: RoadworkTitle::for($roadwork),
            description: self::description($roadwork),
            typeLabel: $type['label'],
            icon: $type['icon'],
            statusKey: $status->value,
            statusLabel: $status->label(),
            period: self::period($roadwork),
            startLabel: self::date($roadwork->start_date) ?? 'Onbekend',
            endLabel: self::date($roadwork->end_date) ?? 'Onbekend',
            duration: self::duration($roadwork),
            phaseLabel: self::phaseLabel($status),
            progress: self::progress($roadwork, $status),
            authority: $roadwork->road_authority,
            locationLabel: $roadwork->road_authority ?? 'Nederland',
            latitude: self::coordinate($roadwork, 'lat'),
            longitude: self::coordinate($roadwork, 'lng'),
            markerColor: $palette['markerColor'],
            chipBg: $palette['chipBg'],
            chipText: $palette['chipText'],
            bannerBg: $palette['bannerBg'],
            bannerText: $palette['bannerText'],
            ringColor: $palette['ringColor'],
            access: self::access($status),
            contact: self::contact($roadwork),
        );
    }

    private static function description(Roadwork $roadwork): string
    {
        $parts = RoadworkTitle::parts($roadwork);

        return $parts !== []
            ? implode(' · ', $parts)
            : 'Voor dit project is nog geen uitgebreide omschrijving beschikbaar.';
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

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->translatedFormat('d M Y');
    }

    private static function duration(Roadwork $roadwork): string
    {
        if ($roadwork->start_date === null || $roadwork->end_date === null) {
            return 'Onbekend';
        }

        $weeks = (int) round(
            CarbonImmutable::parse((string) $roadwork->start_date)
                ->diffInDays(CarbonImmutable::parse((string) $roadwork->end_date)) / 7
        );

        return $weeks <= 1 ? '± 1 week' : "± {$weeks} weken";
    }

    private static function phaseLabel(RoadworkStatus $status): string
    {
        return match ($status) {
            RoadworkStatus::Planned => 'Voorbereiding',
            RoadworkStatus::Done => 'Afgerond',
            RoadworkStatus::Active => 'In uitvoering',
        };
    }

    /**
     * Elapsed fraction of the start→end window, clamped to 0–100. Planned work
     * sits at 0, finished work at 100.
     */
    private static function progress(Roadwork $roadwork, RoadworkStatus $status): int
    {
        if ($status === RoadworkStatus::Planned) {
            return 0;
        }

        if ($status === RoadworkStatus::Done) {
            return 100;
        }

        if ($roadwork->start_date === null || $roadwork->end_date === null) {
            return 40;
        }

        $start = CarbonImmutable::parse((string) $roadwork->start_date);
        $end = CarbonImmutable::parse((string) $roadwork->end_date);
        $total = $start->diffInSeconds($end);

        if ($total <= 0) {
            return 100;
        }

        $elapsed = $start->diffInSeconds(CarbonImmutable::now());

        return (int) max(0, min(100, round($elapsed / $total * 100)));
    }

    /**
     * Generic, status-aware bereikbaarheid items. Melvin has no structured
     * accessibility data, so these communicate the common situation per state.
     *
     * @return list<AccessItem>
     */
    private static function access(RoadworkStatus $status): array
    {
        if ($status === RoadworkStatus::Done) {
            return [
                ['icon' => 'fa-circle-check', 'title' => 'Afgerond', 'text' => 'De straat is volledig opengesteld en hersteld.'],
                ['icon' => 'fa-car', 'title' => 'Auto — ja', 'text' => 'Geen beperkingen meer.'],
                ['icon' => 'fa-square-parking', 'title' => 'Parkeren', 'text' => 'Alle parkeervakken zijn weer beschikbaar.'],
                ['icon' => 'fa-clipboard-list', 'title' => 'Nazorg', 'text' => 'Klachten over de bestrating? Meld het bij de gemeente.'],
            ];
        }

        return [
            ['icon' => 'fa-person-walking', 'title' => 'Te voet — ja', 'text' => 'Woningen blijven te voet bereikbaar.'],
            ['icon' => 'fa-car', 'title' => 'Auto — let op', 'text' => 'Mogelijk een afsluiting of omleiding; volg de borden ter plaatse.'],
            ['icon' => 'fa-square-parking', 'title' => 'Parkeren', 'text' => 'Tijdelijk kunnen parkeervakken zijn opgeheven.'],
            ['icon' => 'fa-trash-can', 'title' => 'Afval', 'text' => 'De inzameling verloopt zoveel mogelijk zoals gebruikelijk.'],
        ];
    }

    private static function contact(Roadwork $roadwork): string
    {
        return $roadwork->road_authority !== null
            ? "Neem contact op met {$roadwork->road_authority} voor vragen over dit project."
            : 'Neem contact op met de wegbeheerder voor vragen over dit project.';
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
