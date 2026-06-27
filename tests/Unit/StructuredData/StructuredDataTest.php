<?php

declare(strict_types=1);

namespace Tests\Unit\StructuredData;

use App\StructuredData\StructuredData;
use Tests\TestCase;

class StructuredDataTest extends TestCase
{
    public function test_empty_collector_renders_nothing(): void
    {
        $this->assertSame('', (new StructuredData)->toScript());
    }

    public function test_pushed_nodes_render_as_one_graph_script(): void
    {
        $sd = new StructuredData;
        $sd->push(['@type' => 'WebSite', 'name' => 'voormijndeur']);
        $sd->push(['@type' => 'Organization', 'name' => 'voormijndeur']);

        $html = $sd->toScript();

        $this->assertStringStartsWith('<script type="application/ld+json">', $html);
        $this->assertStringEndsWith('</script>', $html);

        $json = substr($html, strlen('<script type="application/ld+json">'), -strlen('</script>'));
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertCount(2, $decoded['@graph']);
        $this->assertSame('WebSite', $decoded['@graph'][0]['@type']);
    }

    public function test_script_closing_tags_are_escaped(): void
    {
        $sd = new StructuredData;
        $sd->push(['@type' => 'WebSite', 'name' => '</script><x>']);

        $this->assertStringNotContainsString('</script><x>', $sd->toScript());
    }
}
