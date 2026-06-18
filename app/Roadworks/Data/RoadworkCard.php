<?php

declare(strict_types=1);

namespace App\Roadworks\Data;

use App\Models\Roadwork;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * A roadwork reshaped for the homepage project cards. Melvin's raw fields
 * (kind/severity/causeDescription) are mapped to display title, a coloured
 * badge and a row of meta items.
 *
 * @phpstan-type Badge array{label: string, class: string}
 * @phpstan-type MetaItem array{icon: string, text: string, class: string}
 */
class RoadworkCard extends Data
{
    /**
     * @param  Badge  $badge
     * @param  list<MetaItem>  $meta
     */
    public function __construct(
        public int $id,
        public string $title,
        public string $description,
        public array $badge,
        public array $meta,
        public ?string $authority,
        public string $authorityInitials,
        public ?string $endLabel,
    ) {}

    public static function fromModel(Roadwork $roadwork): self
    {
        return new self(
            id: $roadwork->id,
            title: self::title($roadwork),
            description: self::description($roadwork),
            badge: self::badge($roadwork),
            meta: self::meta($roadwork),
            authority: $roadwork->road_authority,
            authorityInitials: self::initials($roadwork->road_authority),
            endLabel: self::endLabel($roadwork),
        );
    }

    /**
     * Up-to-two-letter initials from the road authority, e.g. "Gemeente
     * Venlo" → "GV". Used for the featured card avatar.
     */
    private static function initials(?string $authority): string
    {
        if ($authority === null || trim($authority) === '') {
            return '–';
        }

        $words = preg_split('/\s+/', trim($authority)) ?: [];
        $letters = array_map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)), $words);

        return implode('', array_slice($letters, 0, 2));
    }

    /**
     * "Tot 15 nov 2026"-style end label for the featured card, or null when no
     * end date is known.
     */
    private static function endLabel(Roadwork $roadwork): ?string
    {
        if ($roadwork->end_date === null) {
            return null;
        }

        return 'Tot '.CarbonImmutable::parse((string) $roadwork->end_date)->translatedFormat('d M Y');
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
            : ($roadwork->road_authority ?? 'Geen omschrijving beschikbaar.');
    }

    /**
     * Split Melvin's comma-packed `causeDescription` ("Overig, , Kademuur
     * vervangen") into clean, de-duplicated parts.
     *
     * @return list<string>
     */
    private static function descriptionParts(Roadwork $roadwork): array
    {
        $raw = data_get($roadwork->feature, 'situation.properties.causeDescription');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_unique($parts));
    }

    /**
     * Colour by severity, label by kind.
     *
     * @return Badge
     */
    private static function badge(Roadwork $roadwork): array
    {
        $label = match ($roadwork->kind) {
            'EVENT' => 'EVENEMENT',
            'WORK' => 'WERKZAAMHEDEN',
            default => 'MELDING',
        };

        $class = match ($roadwork->severity) {
            'high' => 'bg-error text-on-error',
            'medium' => 'bg-secondary-container text-on-secondary-container',
            default => 'bg-primary text-on-primary',
        };

        return ['label' => $label, 'class' => $class];
    }

    /**
     * @return list<MetaItem>
     */
    private static function meta(Roadwork $roadwork): array
    {
        $meta = [];

        if ($roadwork->start_date !== null) {
            $start = CarbonImmutable::parse((string) $roadwork->start_date);
            $isUrgent = $roadwork->severity === 'high';

            $meta[] = [
                'icon' => $isUrgent ? 'warning' : 'schedule',
                'text' => 'Start '.$start->translatedFormat('d M Y'),
                'class' => $isUrgent ? 'text-error' : '',
            ];
        }

        if ($roadwork->road_authority !== null) {
            $meta[] = [
                'icon' => 'location_on',
                'text' => $roadwork->road_authority,
                'class' => '',
            ];
        }

        return $meta;
    }
}
