<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\StructuredData\BreadcrumbListNode;
use App\StructuredData\OrganizationNode;
use App\StructuredData\PlaceNode;
use App\StructuredData\SpecialAnnouncementNode;
use Tests\TestCase;

class DetailNodesTest extends TestCase
{
    public function test_breadcrumb_positions_and_current_item(): void
    {
        $node = BreadcrumbListNode::make([
            ['name' => 'Home', 'url' => 'https://x/'],
            ['name' => 'Werkzaamheden', 'url' => 'https://x/kaart'],
            ['name' => 'Gemeente Utrecht', 'url' => null],
        ]);

        $this->assertSame('BreadcrumbList', $node['@type']);
        $this->assertSame(1, $node['itemListElement'][0]['position']);
        $this->assertSame('https://x/', $node['itemListElement'][0]['item']);
        $this->assertSame(3, $node['itemListElement'][2]['position']);
        $this->assertArrayNotHasKey('item', $node['itemListElement'][2]);
    }

    public function test_place_node_with_and_without_geo(): void
    {
        $with = PlaceNode::make('Catharijnesingel', 52.0894, 5.1132, 'Utrecht', 'Utrecht');
        $this->assertSame('Place', $with['@type']);
        $this->assertSame('NL', $with['address']['addressCountry']);
        $this->assertSame('Utrecht', $with['address']['addressLocality']);
        $this->assertSame(52.0894, $with['geo']['latitude']);

        $without = PlaceNode::make('Onbekend', null, null, null, null);
        $this->assertArrayNotHasKey('geo', $without);
        $this->assertArrayNotHasKey('addressLocality', $without['address']);
    }

    public function test_special_announcement_shape(): void
    {
        $node = SpecialAnnouncementNode::make(
            'Werk A',
            'Kabels / Leidingen',
            'https://x/werk-a',
            '2026-07-01',
            '2026-09-01',
            PlaceNode::make('Plek', 52.0, 5.0, null, null),
            OrganizationNode::make(),
        );

        $this->assertSame('SpecialAnnouncement', $node['@type']);
        $this->assertSame('2026-07-01', $node['datePosted']);
        $this->assertSame('2026-09-01', $node['expires']);
        $this->assertSame('Place', $node['spatialCoverage']['@type']);
        $this->assertSame('Organization', $node['publisher']['@type']);
    }

    public function test_special_announcement_omits_null_dates(): void
    {
        $node = SpecialAnnouncementNode::make(
            'Werk A', 'tekst', 'https://x/werk-a', null, null,
            PlaceNode::make('Plek', null, null, null, null),
            OrganizationNode::make(),
        );

        $this->assertArrayNotHasKey('datePosted', $node);
        $this->assertArrayNotHasKey('expires', $node);
    }
}
