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
 * provides (title, dates, authority, location, hindrance, severity) are
 * populated from the model; the accessibility copy is phrased from the
 * hindrance class where the source has no structured bereikbaarheid data.
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
        public string $hindranceLabel,
        public int $hindranceLevel,
        public string $severityLabel,
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
        $hindrance = Hindrance::for($roadwork);

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
            locationLabel: self::location($roadwork),
            hindranceLabel: $hindrance->label(),
            hindranceLevel: $hindrance->level(),
            severityLabel: Severity::for($roadwork)->label(),
            latitude: self::coordinate($roadwork, 'lat'),
            longitude: self::coordinate($roadwork, 'lng'),
            markerColor: $palette['markerColor'],
            chipBg: $palette['chipBg'],
            chipText: $palette['chipText'],
            bannerBg: $palette['bannerBg'],
            bannerText: $palette['bannerText'],
            ringColor: $palette['ringColor'],
            access: self::access($status, $hindrance),
            contact: self::contact($roadwork),
        );
    }

    /**
     * A readable "wat gaat er gebeuren" line built from Melvin's
     * `causeDescription` (a comma-packed `category, subtype, vrije tekst` field),
     * prefixed with the translated `causeType` for context.
     */
    private static function description(Roadwork $roadwork): string
    {
        $cause = self::cleanCause(data_get($roadwork->feature, 'situation.properties.causeDescription'));
        $type = self::causeTypeLabel(data_get($roadwork->feature, 'situation.properties.causeType'));

        return match (true) {
            $cause !== '' && $type !== null => "{$type}: {$cause}.",
            $cause !== '' => "{$cause}.",
            $type !== null => "{$type}.",
            default => 'Voor dit project is nog geen uitgebreide omschrijving beschikbaar.',
        };
    }

    /**
     * Collapse the comma-/pipe-packed `causeDescription` into a readable phrase:
     * trim each segment, drop the empties Melvin leaves between fields, dedupe,
     * and re-join with commas.
     */
    private static function cleanCause(mixed $raw): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        $segments = array_filter(array_map(trim(...), preg_split('/[,|]/', $raw) ?: []));

        return implode(', ', array_unique($segments));
    }

    /**
     * Melvin's DATEX `causeType` as a Dutch lead-in. `other` and unknown types
     * carry no useful context, so they yield null.
     */
    private static function causeTypeLabel(mixed $causeType): ?string
    {
        return match ($causeType) {
            'roadMaintenance' => 'Wegonderhoud',
            'constructionWork' => 'Bouwwerkzaamheden',
            'publicEvent' => 'Evenement',
            default => null,
        };
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
     * Bereikbaarheid items. Melvin has no structured accessibility data, so the
     * motor-vehicle line is phrased from the {@see Hindrance} class (the only
     * real impact signal the source provides); the rest stays status-aware.
     *
     * @return list<AccessItem>
     */
    private static function access(RoadworkStatus $status, Hindrance $hindrance): array
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
            self::carAccess($hindrance),
            ['icon' => 'fa-square-parking', 'title' => 'Parkeren', 'text' => 'Tijdelijk kunnen parkeervakken zijn opgeheven.'],
            ['icon' => 'fa-trash-can', 'title' => 'Afval', 'text' => 'De inzameling verloopt zoveel mogelijk zoals gebruikelijk.'],
        ];
    }

    /**
     * The motor-vehicle bereikbaarheid line, scaled to the hindrance class.
     *
     * @return AccessItem
     */
    private static function carAccess(Hindrance $hindrance): array
    {
        return match ($hindrance) {
            Hindrance::None => ['icon' => 'fa-car', 'title' => 'Auto — ja', 'text' => 'Nauwelijks hinder; de straat blijft vrijwel normaal bereikbaar.'],
            Hindrance::Limited => ['icon' => 'fa-car', 'title' => 'Auto — let op', 'text' => 'Beperkte hinder; mogelijk een korte stremming. Volg de borden ter plaatse.'],
            Hindrance::Moderate => ['icon' => 'fa-car', 'title' => 'Auto — let op', 'text' => 'Matige hinder; reken op een omleiding. Volg de borden ter plaatse.'],
            Hindrance::Severe, Hindrance::Extreme => ['icon' => 'fa-car', 'title' => 'Auto — beperkt', 'text' => 'Ernstige hinder; een afsluiting is waarschijnlijk. Houd rekening met omrijden.'],
            Hindrance::Unknown => ['icon' => 'fa-car', 'title' => 'Auto — let op', 'text' => 'Mogelijk een afsluiting of omleiding; volg de borden ter plaatse.'],
        };
    }

    /**
     * "Wijk, Gemeente" from the CBS areas this roadwork is linked to, falling
     * back to gemeente alone, then the wegbeheerder, then the country.
     */
    private static function location(Roadwork $roadwork): string
    {
        $gemeente = $roadwork->relationLoaded('gemeenten') ? $roadwork->gemeenten->first()?->name : null;
        $wijk = $roadwork->relationLoaded('wijken') ? $roadwork->wijken->first()?->name : null;

        return match (true) {
            $wijk !== null && $gemeente !== null => "{$wijk}, {$gemeente}",
            $gemeente !== null => $gemeente,
            $roadwork->road_authority !== null => $roadwork->road_authority,
            default => 'Nederland',
        };
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
