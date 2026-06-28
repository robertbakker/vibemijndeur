<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\Roadworks\PopularGemeenten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ListingStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::put('footer:popular-gemeenten', PopularGemeenten::merge([]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graphFrom(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_listing_emits_collection_page_and_breadcrumb(): void
    {
        $graph = $this->graphFrom(
            $this->get('/werkzaamheden')->assertOk()->getContent()
        );

        $byType = [];
        foreach ($graph as $node) {
            $byType[$node['@type']] = $node;
        }

        $this->assertArrayHasKey('CollectionPage', $byType);
        $this->assertArrayHasKey('BreadcrumbList', $byType);

        $this->assertSame('Werkzaamheden in de buurt', $byType['CollectionPage']['name']);
        $this->assertSame('ItemList', $byType['CollectionPage']['mainEntity']['@type']);

        $crumbs = $byType['BreadcrumbList']['itemListElement'];
        $this->assertSame('Home', $crumbs[0]['name']);
        $this->assertSame(url('/'), $crumbs[0]['item']);
        $this->assertSame('Werkzaamheden', $crumbs[1]['name']);
        $this->assertArrayNotHasKey('item', $crumbs[1]);
    }
}
