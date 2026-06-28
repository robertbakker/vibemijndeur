<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\Data\RoadworkCard;
use App\StructuredData\CollectionPageNode;
use App\StructuredData\ItemListNode;
use Tests\TestCase;

class ListingNodesTest extends TestCase
{
    private function card(?string $slug, string $title): RoadworkCard
    {
        return new RoadworkCard(
            id: 1,
            slug: $slug,
            title: $title,
            locationLabel: 'X',
            period: '',
            typeLabel: '',
            icon: '',
            statusKey: 'active',
            statusLabel: '',
            markerColor: '',
            chipBg: '',
            chipText: '',
        );
    }

    public function test_item_list_skips_slugless_cards_and_numbers_contiguously(): void
    {
        $node = ItemListNode::fromCards([
            $this->card('werk-a', 'Werk A'),
            $this->card(null, 'No slug'),
            $this->card('werk-b', 'Werk B'),
        ]);

        $this->assertSame('ItemList', $node['@type']);
        $this->assertSame(2, $node['numberOfItems']);
        $this->assertSame(1, $node['itemListElement'][0]['position']);
        $this->assertSame(url('/werk-a'), $node['itemListElement'][0]['url']);
        $this->assertSame('Werk A', $node['itemListElement'][0]['name']);
        $this->assertSame(2, $node['itemListElement'][1]['position']);
        $this->assertSame('Werk B', $node['itemListElement'][1]['name']);
    }

    public function test_collection_page_wraps_item_list(): void
    {
        $node = CollectionPageNode::make('Werkzaamheden', 'https://x/werkzaamheden', ['@type' => 'ItemList']);

        $this->assertSame('CollectionPage', $node['@type']);
        $this->assertSame('Werkzaamheden', $node['name']);
        $this->assertSame('https://x/werkzaamheden', $node['url']);
        $this->assertSame('ItemList', $node['mainEntity']['@type']);
    }
}
