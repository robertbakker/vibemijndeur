<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\FacetGroup;
use App\Data\RoadworkCard;
use App\Data\RoadworkStatus;
use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Roadwork;
use App\Roadworks\Contracts\RoadworkSearchEngine;
use App\Router\FacetUrlBuilder;
use App\Router\ListingQuery;
use App\StructuredData\BreadcrumbListNode;
use App\StructuredData\CollectionPageNode;
use App\StructuredData\ItemListNode;
use App\StructuredData\StructuredData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The faceted "Werkzaamheden" listing: a text + facet search over the
 * Manticore roadworks index, rendered as cards with disjunctive facet counts
 * (each group's counts ignore that group's own selection, so options never
 * vanish once you pick one).
 */
class WerkzaamhedenController extends Controller
{
    private const int PER_PAGE = 24;

    private const int MAX_FACET_OPTIONS = 12;

    /**
     * Facet group => the index attribute it filters/counts on.
     */
    private const array FACETS = [
        'status' => 'status_key',
        'type' => 'work_type',
        'gemeente' => 'gemeente',
        'provincie' => 'provincie',
        'authority' => 'road_authority',
    ];

    public function __construct(
        private readonly RoadworkSearchEngine $search,
        private readonly StructuredData $structuredData,
        private readonly FacetUrlBuilder $facetUrls,
    ) {}

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

        $term = (string) ($validated['q'] ?? '');
        $sort = $validated['sort'] ?? 'start';
        $page = max(1, (int) ($validated['page'] ?? 1));

        // The canonical entry is the clean pretty URL; this query-string form is
        // back-compat. Reconstruct a ListingQuery from any facet params.
        $query = new ListingQuery;
        foreach (array_values($validated['status'] ?? []) as $status) {
            $query->addStatus($status);
        }
        foreach (array_values($validated['type'] ?? []) as $type) {
            $query->addType($type);
        }
        foreach (array_values($validated['authority'] ?? []) as $authority) {
            $query->addAuthority($authority);
        }
        $this->addAreasByName($query, 'gemeente', array_values($validated['gemeente'] ?? []));
        $this->addAreasByName($query, 'provincie', array_values($validated['provincie'] ?? []));

        return $this->render($query, $term, $sort, $page);
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

        return $this->render($query, $term, $sort, $page);
    }

    /**
     * Resolve facet area names to area rows and add them to the query.
     *
     * @param  list<string>  $names
     */
    private function addAreasByName(ListingQuery $query, string $dimension, array $names): void
    {
        if ($names === []) {
            return;
        }

        $model = $dimension === 'provincie' ? Provincie::class : Gemeente::class;
        foreach ($model::query()->whereIn('name', $names)->get() as $area) {
            $query->addArea($dimension, (int) $area->getKey(), (string) $area->name);
        }
    }

    /**
     * Run the search and render the Inertia page. Shared by the
     * query-string entry ({@see __invoke}) and the pretty-URL entry
     * ({@see renderFromQuery}).
     */
    private function render(ListingQuery $query, string $term, string $sort, int $page): Response
    {
        $filters = $query->toFilters();
        $areaFilters = $query->toAreaFilters();
        $sortExpression = $sort === 'status' ? ['status_order:asc'] : ['start_ts:asc'];

        $main = $this->search->browse(
            $term,
            $filters,
            $sortExpression,
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE,
            [],
            $areaFilters,
        );

        $total = (int) ($main['estimatedTotalHits'] ?? 0);
        $cards = $this->hydrate(array_column($main['hits'] ?? [], 'id'));

        $this->structuredData->push(CollectionPageNode::make(
            'Werkzaamheden in de buurt',
            url()->current(),
            ItemListNode::fromCards($cards),
        ));

        $this->structuredData->push(BreadcrumbListNode::make([
            ['name' => 'Home', 'url' => url('/')],
            ['name' => 'Werkzaamheden', 'url' => null],
        ]));

        return Inertia::render('Werkzaamheden', [
            // Merge so "Meer laden" partial reloads append instead of replacing;
            // a fresh visit (filter change) resets the list.
            'results' => Inertia::merge($cards),
            'facets' => $this->facets($query, $term, $filters, $areaFilters),
            'filters' => [
                'q' => $term,
                'sort' => $sort,
            ],
            'total' => $total,
            'page' => $page,
            'hasMore' => $page * self::PER_PAGE < $total,
        ]);
    }

    /**
     * Load the page's roadworks as cards, preserving the search engine's hit order.
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
     * group's filter applied but not its own. Each group's options are turned
     * into {@see FacetGroup}s whose options carry clean toggle URLs.
     *
     * @param  array<string, list<string>>  $filters
     * @param  array<string, list<string>>  $areaFilters
     * @return array<string, FacetGroup>
     */
    private function facets(ListingQuery $query, string $term, array $filters, array $areaFilters): array
    {
        $distributions = [];
        foreach (self::FACETS as $group => $attribute) {
            // Drop this group's own selection (disjunctive counts).
            $withoutFilters = array_filter($filters, fn (string $k): bool => $k !== $attribute, ARRAY_FILTER_USE_KEY);
            $withoutArea = array_filter($areaFilters, fn (string $k): bool => $k !== $attribute, ARRAY_FILTER_USE_KEY);
            $raw = $this->search->browse($term, $withoutFilters, [], 0, 0, [$attribute], $withoutArea);
            $distributions[$group] = $raw['facetDistribution'][$attribute] ?? [];
        }

        return [
            'status' => new FacetGroup('status', 'Status', $this->facetUrls->options($query, 'status', $this->statusRows($distributions['status'], $filters['status_key'] ?? []))),
            'gemeente' => new FacetGroup('gemeente', 'Gemeente', $this->facetUrls->options($query, 'gemeente', $this->countRows($distributions['gemeente'], $areaFilters['gemeente'] ?? []))),
            'provincie' => new FacetGroup('provincie', 'Provincie', $this->facetUrls->options($query, 'provincie', $this->countRows($distributions['provincie'], $areaFilters['provincie'] ?? []))),
            'type' => new FacetGroup('type', 'Soort werk', $this->facetUrls->options($query, 'type', $this->countRows($distributions['type'], $filters['work_type'] ?? []))),
            'authority' => new FacetGroup('authority', 'Uitvoerder', $this->facetUrls->options($query, 'authority', $this->countRows($distributions['authority'], $filters['road_authority'] ?? []))),
        ];
    }

    /**
     * Status rows in lifecycle order, each with its label and dot colour.
     *
     * @param  array<string, int>  $distribution
     * @param  list<string>  $checked
     * @return list<array<string, mixed>>
     */
    private function statusRows(array $distribution, array $checked): array
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
    private function countRows(array $distribution, array $checked): array
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
