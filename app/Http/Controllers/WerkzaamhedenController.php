<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\RoadworkCard;
use App\Data\RoadworkStatus;
use App\Models\Roadwork;
use App\Roadworks\RoadworkSearch;
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

    /**
     * Facet group => the Meilisearch attribute it filters/counts on.
     */
    private const FACETS = [
        'status' => 'status_key',
        'type' => 'work_type',
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
            'road_authority' => array_values($validated['authority'] ?? []),
        ];
        $filters = array_filter($selected, fn (array $values): bool => $values !== []);

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
                'status' => $selected['status_key'],
                'type' => $selected['work_type'],
                'authority' => $selected['road_authority'],
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
     * @return array{status: list<array<string, mixed>>, type: list<array<string, mixed>>, authority: list<array<string, mixed>>}
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
     * Free-form facet options (type, authority), most results first.
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
            $options[] = [
                'key' => (string) $value,
                'label' => (string) $value,
                'count' => $count,
                'checked' => in_array((string) $value, $checked, true),
            ];
        }

        return $options;
    }
}
