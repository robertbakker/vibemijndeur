<?php

declare(strict_types=1);

namespace App\Router;

use App\Data\FacetOption;
use App\Models\Gemeente;
use App\Models\Provincie;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns Meilisearch facet rows into {@see FacetOption} DTOs, each carrying the
 * clean URL you land on after toggling that one value against the current query.
 */
final class FacetUrlBuilder
{
    /** @var array<string, class-string<Model>> area dimension => model */
    private const AREA_MODELS = [
        'gemeente' => Gemeente::class,
        'provincie' => Provincie::class,
    ];

    public function __construct(private readonly ListingUrlMapper $mapper) {}

    /**
     * @param  list<array{key:string,label:string,count:int,checked:bool,dot?:string}>  $rawOptions
     * @return list<FacetOption>
     */
    public function options(ListingQuery $current, string $dimension, array $rawOptions): array
    {
        $out = [];
        foreach ($rawOptions as $raw) {
            $toggled = $this->toggle($current, $dimension, $raw['key'], $raw['checked']);
            $out[] = new FacetOption(
                key: $raw['key'],
                label: $raw['label'],
                count: $raw['count'],
                checked: $raw['checked'],
                url: $this->mapper->build($toggled),
                dot: $raw['dot'] ?? null,
            );
        }

        return $out;
    }

    private function toggle(ListingQuery $current, string $dimension, string $key, bool $checked): ListingQuery
    {
        $next = $this->clone($current);

        match ($dimension) {
            'status' => $checked ? $next->removeStatus($key) : $next->addStatus($key),
            'type' => $checked ? $next->removeType($key) : $next->addType($key),
            'authority' => $checked ? $next->removeAuthority($key) : $next->addAuthority($key),
            'gemeente', 'provincie' => $this->toggleArea($next, $dimension, $key, $checked),
            default => null,
        };

        return $next;
    }

    private function toggleArea(ListingQuery $query, string $dimension, string $name, bool $checked): void
    {
        if ($checked) {
            $query->removeAreaByName($name);

            return;
        }

        /** @var class-string<Model> $model */
        $model = self::AREA_MODELS[$dimension];
        foreach ($model::query()->where('name', $name)->get() as $area) {
            $query->addArea($dimension, (int) $area->getKey(), (string) $area->name);
        }
    }

    private function clone(ListingQuery $query): ListingQuery
    {
        $copy = new ListingQuery;
        foreach ($query->areas() as $area) {
            $copy->addArea($area['level'], $area['id'], $area['name']);
        }
        foreach ($query->statuses() as $status) {
            $copy->addStatus($status);
        }
        foreach ($query->types() as $type) {
            $copy->addType($type);
        }
        foreach ($query->authorities() as $authority) {
            $copy->addAuthority($authority);
        }

        return $copy;
    }
}
