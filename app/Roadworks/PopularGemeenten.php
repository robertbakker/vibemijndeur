<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Roadworks\Contracts\RoadworkSearchEngine;
use Illuminate\Support\Facades\Cache;

/**
 * Live roadwork counts for the footer's "Werkzaamheden per gemeente" cloud:
 * the 24 biggest Dutch municipalities, each with the number of roadworks
 * currently in the search index.
 *
 * Counts come from a single `gemeente` facet distribution (one search with
 * `limit: 0`), cached so the shared footer prop costs at most one search
 * call per hour rather than one per request.
 */
final readonly class PopularGemeenten
{
    private const string CACHE_KEY = 'footer:popular-gemeenten';

    /**
     * The 24 largest municipalities by population, paired with the CBS gemeente
     * name used in the index. Display labels use the everyday spelling, so a
     * couple differ from their official CBS name.
     *
     * @var list<array{label: string, gemeente: string}>
     */
    public const array CITIES = [
        ['label' => 'Amsterdam', 'gemeente' => 'Amsterdam'],
        ['label' => 'Rotterdam', 'gemeente' => 'Rotterdam'],
        ['label' => 'Den Haag', 'gemeente' => "'s-Gravenhage"],
        ['label' => 'Utrecht', 'gemeente' => 'Utrecht'],
        ['label' => 'Eindhoven', 'gemeente' => 'Eindhoven'],
        ['label' => 'Groningen', 'gemeente' => 'Groningen'],
        ['label' => 'Tilburg', 'gemeente' => 'Tilburg'],
        ['label' => 'Almere', 'gemeente' => 'Almere'],
        ['label' => 'Breda', 'gemeente' => 'Breda'],
        ['label' => 'Nijmegen', 'gemeente' => 'Nijmegen'],
        ['label' => 'Haarlem', 'gemeente' => 'Haarlem'],
        ['label' => 'Arnhem', 'gemeente' => 'Arnhem'],
        ['label' => 'Zaanstad', 'gemeente' => 'Zaanstad'],
        ['label' => 'Amersfoort', 'gemeente' => 'Amersfoort'],
        ['label' => 'Apeldoorn', 'gemeente' => 'Apeldoorn'],
        ['label' => 'Den Bosch', 'gemeente' => "'s-Hertogenbosch"],
        ['label' => 'Maastricht', 'gemeente' => 'Maastricht'],
        ['label' => 'Leiden', 'gemeente' => 'Leiden'],
        ['label' => 'Dordrecht', 'gemeente' => 'Dordrecht'],
        ['label' => 'Zwolle', 'gemeente' => 'Zwolle'],
        ['label' => 'Delft', 'gemeente' => 'Delft'],
        ['label' => 'Alkmaar', 'gemeente' => 'Alkmaar'],
        ['label' => 'Deventer', 'gemeente' => 'Deventer'],
        ['label' => 'Leeuwarden', 'gemeente' => 'Leeuwarden'],
    ];

    public function __construct(private RoadworkSearchEngine $search) {}

    /**
     * The 24 municipalities with their live roadwork counts, cached for an hour.
     *
     * @return list<array{label: string, gemeente: string, count: int}>
     */
    public function counts(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): array {
            $raw = $this->search->browse('', [], [], 0, 0, ['gemeente']);

            return self::merge($raw['facetDistribution']['gemeente'] ?? []);
        });
    }

    /**
     * Pair every configured municipality with its count from a gemeente facet
     * distribution; municipalities absent from the distribution count as zero.
     *
     * @param  array<string, int>  $distribution
     * @return list<array{label: string, gemeente: string, count: int}>
     */
    public static function merge(array $distribution): array
    {
        return array_map(fn (array $city): array => [
            'label' => $city['label'],
            'gemeente' => $city['gemeente'],
            'count' => (int) ($distribution[$city['gemeente']] ?? 0),
        ], self::CITIES);
    }
}
