<?php

declare(strict_types=1);

namespace Tests\Feature\StructuredData;

use App\Models\Roadwork;
use App\Roadworks\PopularGemeenten;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DetailStructuredDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed the cache so the shared popularGemeenten prop resolves
        // without hitting Meilisearch (same pattern as FooterGemeentenTest).
        Cache::put('footer:popular-gemeenten', PopularGemeenten::merge([
            'Amsterdam' => 125,
            "'s-Gravenhage" => 186,
        ]));
    }

    private function upsert(): Roadwork
    {
        $line = ['type' => 'LineString', 'coordinates' => [[4.89, 52.37], [4.90, 52.37]]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $line, 'properties' => ['causeDescription' => 'GAS Hoofdstraat']], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX', 'NDW_SD_1',
            ['kind' => 'WORK', 'severity' => 'high', 'status' => 'running', 'road_authority' => "Gemeente 's-Gravenhage", 'published' => true, 'start_date' => '2026-07-01T00:00:00Z', 'end_date' => '2026-09-01T00:00:00Z'],
            $line, $doc, CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        return Roadwork::where('source_id', 'NDW_SD_1')->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function graphFrom(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_detail_emits_special_announcement_and_breadcrumb(): void
    {
        $this->upsert();

        $graph = $this->graphFrom(
            $this->get('/s-gravenhage-gas-hoofdstraat')->assertOk()->getContent()
        );

        $byType = [];
        foreach ($graph as $node) {
            $byType[$node['@type']] = $node;
        }

        $this->assertArrayHasKey('SpecialAnnouncement', $byType);
        $this->assertArrayHasKey('BreadcrumbList', $byType);

        $announcement = $byType['SpecialAnnouncement'];
        $this->assertSame('GAS Hoofdstraat', $announcement['name']);
        $this->assertSame('2026-07-01', $announcement['datePosted']);
        $this->assertSame('2026-09-01', $announcement['expires']);
        $this->assertSame('Place', $announcement['spatialCoverage']['@type']);
        $this->assertSame('NL', $announcement['spatialCoverage']['address']['addressCountry']);
        $this->assertArrayHasKey('geo', $announcement['spatialCoverage']);
        $this->assertSame('voormijndeur', $announcement['publisher']['name']);

        $crumbs = $byType['BreadcrumbList']['itemListElement'];
        $this->assertSame('Home', $crumbs[0]['name']);
        $this->assertSame(url('/'), $crumbs[0]['item']);
        $this->assertSame('Werkzaamheden', $crumbs[1]['name']);
        $this->assertSame(url('/kaart'), $crumbs[1]['item']);
        $this->assertSame("Gemeente 's-Gravenhage", $crumbs[2]['name']);
        $this->assertArrayNotHasKey('item', $crumbs[2]);
    }
}
