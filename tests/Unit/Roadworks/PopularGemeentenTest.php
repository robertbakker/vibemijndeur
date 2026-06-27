<?php

declare(strict_types=1);

namespace Tests\Unit\Roadworks;

use App\Roadworks\PopularGemeenten;
use Tests\TestCase;

class PopularGemeentenTest extends TestCase
{
    public function test_merge_pairs_every_city_with_its_count(): void
    {
        $merged = PopularGemeenten::merge(['Amsterdam' => 125, 'Breda' => 298]);

        $this->assertCount(count(PopularGemeenten::CITIES), $merged);

        $byGemeente = collect($merged)->keyBy('gemeente');
        $this->assertSame(125, $byGemeente['Amsterdam']['count']);
        $this->assertSame(298, $byGemeente['Breda']['count']);
    }

    public function test_merge_maps_colloquial_labels_to_cbs_gemeente_names(): void
    {
        $merged = PopularGemeenten::merge([
            "'s-Gravenhage" => 186,
            "'s-Hertogenbosch" => 143,
        ]);

        $denHaag = collect($merged)->firstWhere('label', 'Den Haag');
        $denBosch = collect($merged)->firstWhere('label', 'Den Bosch');

        $this->assertSame("'s-Gravenhage", $denHaag['gemeente']);
        $this->assertSame(186, $denHaag['count']);
        $this->assertSame("'s-Hertogenbosch", $denBosch['gemeente']);
        $this->assertSame(143, $denBosch['count']);
    }

    public function test_merge_counts_missing_municipalities_as_zero(): void
    {
        $merged = PopularGemeenten::merge([]);

        foreach ($merged as $city) {
            $this->assertSame(0, $city['count']);
        }
    }
}
