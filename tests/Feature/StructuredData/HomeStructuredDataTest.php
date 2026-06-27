<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\Roadworks\PopularGemeenten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomeStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the cache so the shared popularGemeenten prop resolves
        // without hitting Meilisearch (same pattern as FooterGemeentenTest).
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

    public function test_home_emits_website_and_organization(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $types = array_column($this->graphFrom($html), '@type');

        $this->assertContains('WebSite', $types);
        $this->assertContains('Organization', $types);
    }
}
