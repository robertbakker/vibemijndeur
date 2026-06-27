<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\StructuredData\OrganizationNode;
use App\StructuredData\WebSiteNode;
use Tests\TestCase;

class SiteNodesTest extends TestCase
{
    public function test_website_node_shape(): void
    {
        $node = WebSiteNode::make();

        $this->assertSame('WebSite', $node['@type']);
        $this->assertSame('voormijndeur', $node['name']);
        $this->assertSame(url('/'), $node['url']);
    }

    public function test_organization_node_shape(): void
    {
        $node = OrganizationNode::make();

        $this->assertSame('Organization', $node['@type']);
        $this->assertSame('voormijndeur', $node['name']);
        $this->assertSame(url('/'), $node['url']);
    }
}
