<?php

declare(strict_types=1);

namespace Tests\Unit\Roadworks;

use App\Roadworks\RoadworkSearch;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RoadworkSearchFilterTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,list<string>>  $areaFilters
     * @return list<string|list<string>>
     */
    private function build(array $filters, array $areaFilters): array
    {
        $method = new ReflectionMethod(RoadworkSearch::class, 'buildFilter');

        return $method->invoke(new RoadworkSearch, $filters, $areaFilters);
    }

    public function test_dimension_filters_are_anded(): void
    {
        $filter = $this->build(['status_key' => ['planned']], []);

        $this->assertSame(['status_key IN ["planned"]'], $filter);
    }

    public function test_area_filters_become_a_single_or_group(): void
    {
        $filter = $this->build(
            ['status_key' => ['planned']],
            ['gemeente' => ['Amsterdam', 'Utrecht'], 'provincie' => ['Noord-Holland']],
        );

        $this->assertSame([
            'status_key IN ["planned"]',
            ['gemeente IN ["Amsterdam", "Utrecht"]', 'provincie IN ["Noord-Holland"]'],
        ], $filter);
    }

    public function test_single_area_attribute_is_not_wrapped(): void
    {
        $filter = $this->build([], ['gemeente' => ['Amsterdam']]);

        $this->assertSame(['gemeente IN ["Amsterdam"]'], $filter);
    }
}
