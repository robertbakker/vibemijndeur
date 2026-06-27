<?php

declare(strict_types=1);

namespace Tests\Unit\Router;

use App\Router\ListingQuery;
use PHPUnit\Framework\TestCase;

class ListingQueryTest extends TestCase
{
    public function test_it_maps_area_and_facets_to_meili_filters(): void
    {
        $query = new ListingQuery;
        $query->setArea('gemeente', 42, 'Amsterdam');
        $query->addStatus('active');
        $query->addType('Wegdek');
        $query->addAuthority('Rijkswaterstaat');

        $this->assertSame([
            'gemeente' => ['Amsterdam'],
            'status_key' => ['active'],
            'work_type' => ['Wegdek'],
            'road_authority' => ['Rijkswaterstaat'],
        ], $query->toFilters());

        $this->assertSame(['level' => 'gemeente', 'id' => 42, 'name' => 'Amsterdam'], $query->area());
    }

    public function test_cache_key_changes_with_state(): void
    {
        $a = new ListingQuery;
        $a->setArea('gemeente', 1, 'Amsterdam');
        $b = new ListingQuery;
        $b->setArea('gemeente', 2, 'Rotterdam');

        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }
}
