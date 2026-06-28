<?php

declare(strict_types=1);

namespace App\Roadworks;

use App\Data\Suggestion;
use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Wijk;
use App\Roadworks\Contracts\RoadworkSearchEngine;
use App\Router\ListingQuery;
use App\Router\ListingUrlMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * Builds ranked autosuggest results from the search engine's facet search: each matched
 * facet value becomes one or more {@see Suggestion}s linking to a pretty
 * listing URL (built via {@see ListingUrlMapper}).
 */
final readonly class SuggestionService
{
    /**
     * Area facets => the Eloquent model whose `name` the facet value resolves to.
     *
     * @var array<string, class-string<Model>>
     */
    private const array AREA_MODELS = [
        'gemeente' => Gemeente::class,
        'provincie' => Provincie::class,
        'wijk' => Wijk::class,
        'buurt' => Buurt::class,
    ];

    /** The facets searched, in display order. road_authority has no area model. */
    private const array FACETS = ['gemeente', 'provincie', 'wijk', 'buurt', 'road_authority'];

    private const int PER_FACET = 20;

    public function __construct(
        private RoadworkSearchEngine $search,
        private ListingUrlMapper $mapper,
    ) {}

    /**
     * @return list<Suggestion>
     */
    public function suggest(?string $term, int $limit = 10): array
    {
        $term = trim((string) $term);
        if ($term === '') {
            return [];
        }

        try {
            $suggestions = [];
            foreach (self::FACETS as $facet) {
                foreach ($this->search->facetValues($facet, $term, self::PER_FACET) as $hit) {
                    foreach ($this->toSuggestions($facet, $hit['value'], $hit['count']) as $suggestion) {
                        $suggestions[] = $suggestion;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('autosuggest failed', ['exception' => $e]);

            return [];
        }

        return array_slice($this->rank($suggestions, $term), 0, $limit);
    }

    /**
     * One matched facet value → suggestions. Area values resolve to every area
     * row sharing that name (ambiguous names yield several distinct URLs);
     * road_authority resolves directly.
     *
     * @return list<Suggestion>
     */
    private function toSuggestions(string $facet, string $value, int $count): array
    {
        if (! array_key_exists($facet, self::AREA_MODELS)) {
            $query = new ListingQuery;
            $query->addAuthority($value);

            return [new Suggestion($facet, $value, $this->mapper->build($query), $count)];
        }

        /** @var class-string<Model> $model */
        $model = self::AREA_MODELS[$facet];

        $suggestions = [];
        foreach ($model::query()->where('name', $value)->get() as $area) {
            $query = new ListingQuery;
            $query->addArea($facet, (int) $area->getKey(), (string) $area->name);
            try {
                $suggestions[] = new Suggestion($facet, $value, $this->mapper->build($query), $count);
            } catch (ModelNotFoundException) {
                // Area exists in the index but has no current slug; skip it (cannot build a URL).
                Log::warning('autosuggest: area has no current slug', [
                    'facet' => $facet,
                    'area_id' => $area->getKey(),
                    'name' => $value,
                ]);
            }
        }

        return $suggestions;
    }

    /**
     * Match-quality bucket (exact < prefix < other) against the term, then
     * count desc. Stable for equal keys.
     *
     * @param  list<Suggestion>  $suggestions
     * @return list<Suggestion>
     */
    private function rank(array $suggestions, string $term): array
    {
        usort($suggestions, fn (Suggestion $a, Suggestion $b): int => [$this->bucket($a->label, $term), -$a->count]
            <=> [$this->bucket($b->label, $term), -$b->count]);

        return $suggestions;
    }

    private function bucket(string $label, string $term): int
    {
        $label = mb_strtolower($label);
        $term = mb_strtolower($term);

        if ($label === $term) {
            return 0;
        }

        return str_starts_with($label, $term) ? 1 : 2;
    }
}
