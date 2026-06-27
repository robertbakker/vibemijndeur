<?php

declare(strict_types=1);

namespace App\Router;

final class ListingQuery
{
    /** @var array{level:string,id:int,name:string}|null */
    private ?array $area = null;

    /** @var list<string> */
    private array $statuses = [];

    /** @var list<string> */
    private array $types = [];

    /** @var list<string> */
    private array $authorities = [];

    private ?string $term = null;

    private ?string $sort = null;

    private int $page = 1;

    public function setArea(string $level, int $id, string $name): void
    {
        $this->area = ['level' => $level, 'id' => $id, 'name' => $name];
    }

    /** @return array{level:string,id:int,name:string}|null */
    public function area(): ?array
    {
        return $this->area;
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

    /** @return array<string, list<string>> */
    public function toFilters(): array
    {
        $filters = [];

        if ($this->area !== null) {
            $filters[$this->area['level']] = [$this->area['name']];
        }
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

    public function cacheKey(): string
    {
        return md5(serialize([
            $this->area, $this->statuses, $this->types,
            $this->authorities, $this->term, $this->sort, $this->page,
        ]));
    }
}
