<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Roadworks\PopularGemeenten;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FooterGemeentenTest extends TestCase
{
    public function test_pages_share_popular_gemeenten_counts_with_the_footer(): void
    {
        // Seed the cache so the shared prop resolves without hitting Meilisearch.
        Cache::put('footer:popular-gemeenten', PopularGemeenten::merge([
            'Amsterdam' => 125,
            "'s-Gravenhage" => 186,
        ]));

        $this->get(route('kaart'))->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->has('popularGemeenten', count(PopularGemeenten::CITIES))
                ->where('popularGemeenten.0', [
                    'label' => 'Amsterdam',
                    'gemeente' => 'Amsterdam',
                    'count' => 125,
                ])
                ->where('popularGemeenten.2', [
                    'label' => 'Den Haag',
                    'gemeente' => "'s-Gravenhage",
                    'count' => 186,
                ]),
        );
    }
}
