<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\RoadworkCard;
use App\Data\RoadworkStatus;
use App\Models\Roadwork;
use App\Roadworks\RoadworkSearch;
use App\Router\ListingQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The faceted "Werkzaamheden" listing: a text + facet search over the
 * Meilisearch roadworks index, rendered as cards with disjunctive facet counts
 * (each group's counts ignore that group's own selection, so options never
 * vanish once you pick one).
 */
class WerkzaamhedenController extends Controller
{
    private const PER_PAGE = 24;

    private const MAX_FACET_OPTIONS = 12;

    /**
     * Facet group => the Meilisearch attribute it filters/counts on.
     */
    private const FACETS = [
        'status' => 'status_key',
        'type' => 'work_type',
        'gemeente' => 'gemeente',
        'provincie' => 'provincie',
        'authority' => 'road_authority',
    ];

    public function __construct(private readonly RoadworkSearch $search) {}

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string'],
            'type' => ['nullable', 'array'],
            'type.*' => ['string'],
            'gemeente' => ['nullable', 'array'],
            'gemeente.*' => ['string'],
            'provincie' => ['nullable', 'array'],
            'provincie.*' => ['string'],
            'authority' => ['nullable', 'array'],
            'authority.*' => ['string'],
            'sort' => ['nullable', 'in:start,status'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = (string) ($validated['q'] ?? '');
        $sort = $validated['sort'] ?? 'start';
        $page = max(1, (int) ($validated['page'] ?? 1));

        // Active filters keyed by the Meilisearch attribute they constrain.
        $selected = [
            'status_key' => array_values($validated['status'] ?? []),
            'work_type' => array_values($validated['type'] ?? []),
            'gemeente' => array_values($validated['gemeente'] ?? []),
            'provincie' => array_values($validated['provincie'] ?? []),
            'road_authority' => array_values($validated['authority'] ?? []),
        ];
        $filters = array_filter($selected, fn (array $values): bool => $values !== []);

        return $this->render($query, $filters, $sort, $page);
    }

    /**
     * Render the listing from a resolved pretty-URL {@see ListingQuery},
     * merged with query-string refinements (q/sort/page).
     */
    public function renderFromQuery(ListingQuery $query, Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:start,status'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $term = (string) ($validated['q'] ?? $query->term() ?? '');
        $sort = $validated['sort'] ?? $query->sort() ?? 'start';
        $page = max(1, (int) ($validated['page'] ?? $query->page()));

        return $this->render($term, $query->toFilters(), $sort, $page);
    }

    /**
     * Run the Meili search and render the Inertia page. Shared by the
     * query-string entry ({@see __invoke}) and the pretty-URL entry
     * ({@see renderFromQuery}).
     *
     * @param  array<string, list<string>>  $filters  keyed by Meili attribute
     */
    private function render(string $query, array $filters, string $sort, int $page): Response
    {
        $sortExpression = $sort === 'status' ? ['status_order:asc'] : ['start_ts:asc'];

        $main = $this->search->browse(
            $query,
            $filters,
            $sortExpression,
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE,
        );

        $total = (int) ($main['estimatedTotalHits'] ?? 0);
        $cards = $this->hydrate(array_column($main['hits'] ?? [], 'id'));

        return Inertia::render('Werkzaamheden', [
            // Merge so "Meer laden" partial reloads append instead of replacing;
            // a fresh visit (filter change) resets the list.
            'results' => Inertia::merge($cards),
            'facets' => $this->facets($query, $filters),
            'filters' => [
                'q' => $query,
                'status' => $filters['status_key'] ?? [],
                'type' => $filters['work_type'] ?? [],
                'gemeente' => $filters['gemeente'] ?? [],
                'provincie' => $filters['provincie'] ?? [],
                'authority' => $filters['road_authority'] ?? [],
                'sort' => $sort,
            ],
            'total' => $total,
            'page' => $page,
            'hasMore' => $page * self::PER_PAGE < $total,
        ]);
    }

    /**
     * Load the page's roadworks as cards, preserving Meilisearch's hit order.
     *
     * @param  list<int>  $ids
     * @return list<RoadworkCard>
     */
    private function hydrate(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $models = Roadwork::query()
            ->whereIn('id', $ids)
            ->with('currentSlug')
            ->get()
            ->keyBy('id');

        $cards = [];
        foreach ($ids as $id) {
            $model = $models->get($id);
            if ($model !== null) {
                $cards[] = RoadworkCard::fromModel($model);
            }
        }

        return $cards;
    }

    /**
     * Disjunctive facet counts: each group is counted with every *other*
     * group's filter applied but not its own.
     *
     * @param  array<string, list<string>>  $filters
     * @return array<string, list<array<string, mixed>>>
     */
    private function facets(string $query, array $filters): array
    {
        $distributions = [];
        foreach (self::FACETS as $group => $attribute) {
            $without = array_filter(
                $filters,
                fn (string $key): bool => $key !== $attribute,
                ARRAY_FILTER_USE_KEY,
            );
            $raw = $this->search->browse($query, $without, [], 0, 0, [$attribute]);
            $distributions[$group] = $raw['facetDistribution'][$attribute] ?? [];
        }

        return [
            'status' => $this->statusOptions($distributions['status'], $filters['status_key'] ?? []),
            'type' => $this->countOptions($distributions['type'], $filters['work_type'] ?? []),
            'gemeente' => $this->countOptions($distributions['gemeente'], $filters['gemeente'] ?? []),
            'provincie' => $this->countOptions($distributions['provincie'], $filters['provincie'] ?? []),
            'authority' => $this->countOptions($distributions['authority'], $filters['road_authority'] ?? []),
        ];
    }

    /**
     * Status options in lifecycle order, each with its label and dot colour.
     *
     * @param  array<string, int>  $distribution
     * @param  list<string>  $checked
     * @return list<array<string, mixed>>
     */
    private function statusOptions(array $distribution, array $checked): array
    {
        $options = [];
        foreach (RoadworkStatus::cases() as $status) {
            $options[] = [
                'key' => $status->value,
                'label' => $status->label(),
                'dot' => $status->palette()['markerColor'],
                'count' => $distribution[$status->value] ?? 0,
                'checked' => in_array($status->value, $checked, true),
            ];
        }

        return $options;
    }

    /**
     * Free-form facet options (type, gemeente, provincie, authority), most
     * results first and capped — long lists (gemeenten, uitvoerders) would
     * otherwise swamp the sidebar. Currently-selected values are always kept.
     *
     * @param  array<string, int>  $distribution
     * @param  list<string>  $checked
     * @return list<array<string, mixed>>
     */
    private function countOptions(array $distribution, array $checked): array
    {
        arsort($distribution);

        $options = [];
        foreach ($distribution as $value => $count) {
            $value = (string) $value;
            $isChecked = in_array($value, $checked, true);
            if (count($options) >= self::MAX_FACET_OPTIONS && ! $isChecked) {
                continue;
            }
            $options[] = [
                'key' => $value,
                'label' => $value,
                'count' => $count,
                'checked' => $isChecked,
            ];
        }

        return $options;
    }
}
