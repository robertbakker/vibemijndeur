<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Models\Roadwork;

/**
 * Maps a roadwork to a human "soort" label and a Font Awesome icon, by scanning
 * Melvin's free-text fields (activity type + cause description) for known work
 * types. Falls back to a generic construction icon.
 *
 * @phpstan-type TypeView array{label: string, icon: string}
 */
final class RoadworkType
{
    /**
     * Keyword → [label, Font Awesome class]. First match (in order) wins.
     *
     * @var list<array{0: list<string>, 1: string, 2: string}>
     */
    private const RULES = [
        [['gas'], 'Gas', 'fa-fire-flame-simple'],
        [['riol', 'riool'], 'Riool', 'fa-droplet'],
        [['water', 'drinkwater'], 'Water', 'fa-faucet-drip'],
        [['glasvezel', 'fiber'], 'Glasvezel', 'fa-wifi'],
        [['kabel', 'elektr', 'stroom', 'electr'], 'Kabels', 'fa-bolt'],
        [['asfalt', 'wegdek', 'bestrating', 'herstraat', 'klinker'], 'Wegdek', 'fa-road'],
        [['brug', 'kade'], 'Brug & kade', 'fa-bridge'],
        [['boom', 'groen', 'snoei'], 'Groen', 'fa-tree'],
        [['evenement', 'markt'], 'Evenement', 'fa-calendar-day'],
    ];

    /**
     * The distinct human labels (fallback included), for the type facet.
     *
     * @return list<string>
     */
    public static function labels(): array
    {
        $labels = array_map(static fn (array $rule): string => $rule[1], self::RULES);
        $labels[] = 'Werkzaamheden';

        return array_values(array_unique($labels));
    }

    /**
     * @return TypeView
     */
    public static function for(Roadwork $roadwork): array
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $roadwork->activity_type,
            implode(' ', RoadworkTitle::parts($roadwork)),
        ])));

        foreach (self::RULES as [$keywords, $label, $icon]) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return ['label' => $label, 'icon' => $icon];
                }
            }
        }

        return ['label' => 'Werkzaamheden', 'icon' => 'fa-person-digging'];
    }
}
