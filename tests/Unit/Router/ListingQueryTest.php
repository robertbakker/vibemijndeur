<?php

declare(strict_types=1);

namespace Tests\Unit\Router;

use App\Router\ListingQuery;
use PHPUnit\Framework\TestCase;

class ListingQueryTest extends TestCase
{
    public function test_it_collects_multiple_areas(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addArea('gemeente', 2, 'Utrecht');

        $this->assertCount(2, $query->areas());
        $this->assertTrue($query->hasAreaName('Utrecht'));
    }

    public function test_remove_area_by_name(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addArea('provincie', 9, 'Utrecht');
        $query->removeAreaByName('Amsterdam');

        $this->assertSame([['level' => 'provincie', 'id' => 9, 'name' => 'Utrecht']], $query->areas());
    }

    public function test_to_filters_excludes_area_and_keeps_dimensions(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addStatus('planned');
        $query->addType('Wegdek');
        $query->addAuthority('Rijkswaterstaat');

        $this->assertSame([
            'status_key' => ['planned'],
            'work_type' => ['Wegdek'],
            'road_authority' => ['Rijkswaterstaat'],
        ], $query->toFilters());
    }

    public function test_to_area_filters_groups_by_attribute(): void
    {
        $query = new ListingQuery;
        $query->addArea('gemeente', 1, 'Amsterdam');
        $query->addArea('gemeente', 2, 'Utrecht');
        $query->addArea('provincie', 9, 'Noord-Holland');

        $this->assertSame([
            'gemeente' => ['Amsterdam', 'Utrecht'],
            'provincie' => ['Noord-Holland'],
        ], $query->toAreaFilters());
    }

    public function test_cache_key_changes_with_state(): void
    {
        $a = new ListingQuery;
        $a->addArea('gemeente', 1, 'Amsterdam');
        $b = new ListingQuery;
        $b->addArea('gemeente', 2, 'Rotterdam');

        $this->assertNotSame($a->cacheKey(), $b->cacheKey());
    }
}
