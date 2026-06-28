<?php

declare(strict_types=1);

namespace App\Router;

final class ListingQuery
{
    /** @var list<array{level:string,id:int,name:string}> */
    private array $areas = [];

    /** @var list<string> */
    private array $statuses = [];

    /** @var list<string> */
    private array $types = [];

    /** @var list<string> */
    private array $authorities = [];

    private ?string $term = null;

    private ?string $sort = null;

    private int $page = 1;

    public function addArea(string $level, int $id, string $name): void
    {
        foreach ($this->areas as $existing) {
            if ($existing['id'] === $id && $existing['level'] === $level) {
                return;
            }
        }
        $this->areas[] = ['level' => $level, 'id' => $id, 'name' => $name];
    }

    public function removeAreaByName(string $name): void
    {
        $this->areas = array_values(array_filter(
            $this->areas,
            fn (array $a): bool => $a['name'] !== $name,
        ));
    }

    /** @return list<array{level:string,id:int,name:string}> */
    public function areas(): array
    {
        return $this->areas;
    }

    public function hasAreaName(string $name): bool
    {
        foreach ($this->areas as $a) {
            if ($a['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    public function addStatus(string $key): void
    {
        $this->statuses[] = $key;
    }

    public function addType(string $label): void
    {
        $this->types[] = $label;
    }

    public function addAuthority(string $name): void
    {
        $this->authorities[] = $name;
    }

    public function removeStatus(string $key): void
    {
        $this->statuses = array_values(array_filter($this->statuses, fn (string $s): bool => $s !== $key));
    }

    public function removeType(string $label): void
    {
        $this->types = array_values(array_filter($this->types, fn (string $t): bool => $t !== $label));
    }

    public function removeAuthority(string $name): void
    {
        $this->authorities = array_values(array_filter($this->authorities, fn (string $a): bool => $a !== $name));
    }

    public function hasStatus(string $key): bool
    {
        return in_array($key, $this->statuses, true);
    }

    public function hasType(string $label): bool
    {
        return in_array($label, $this->types, true);
    }

    public function hasAuthority(string $name): bool
    {
        return in_array($name, $this->authorities, true);
    }

    /** @return list<string> */
    public function statuses(): array
    {
        return $this->statuses;
    }

    /** @return list<string> */
    public function types(): array
    {
        return $this->types;
    }

    /** @return list<string> */
    public function authorities(): array
    {
        return $this->authorities;
    }

    public function setTerm(?string $term): void
    {
        $this->term = $term;
    }

    public function term(): ?string
    {
        return $this->term;
    }

    public function setSort(?string $sort): void
    {
        $this->sort = $sort;
    }

    public function sort(): ?string
    {
        return $this->sort;
    }

    public function setPage(int $page): void
    {
        $this->page = max(1, $page);
    }

    public function page(): int
    {
        return $this->page;
    }

    /**
     * Non-area filters keyed by Meilisearch attribute (AND-combined upstream).
     *
     * @return array<string, list<string>>
     */
    public function toFilters(): array
    {
        $filters = [];

        if ($this->statuses !== []) {
            $filters['status_key'] = $this->statuses;
        }
        if ($this->types !== []) {
            $filters['work_type'] = $this->types;
        }
        if ($this->authorities !== []) {
            $filters['road_authority'] = $this->authorities;
        }

        return $filters;
    }

    /**
     * Area names grouped by their filterable Meilisearch attribute. Only
     * gemeente/provincie are indexed as facets; other levels are dropped.
     *
     * @return array<string, list<string>>
     */
    public function toAreaFilters(): array
    {
        $attribute = ['gemeente' => 'gemeente', 'provincie' => 'provincie'];

        $grouped = [];
        foreach ($this->areas as $area) {
            $key = $attribute[$area['level']] ?? null;
            if ($key === null) {
                continue;
            }
            $grouped[$key][] = $area['name'];
        }

        return $grouped;
    }

    public function cacheKey(): string
    {
        return md5(serialize([
            $this->areas, $this->statuses, $this->types,
            $this->authorities, $this->term, $this->sort, $this->page,
        ]));
    }
}
