<?php

declare(strict_types=1);

namespace Tests\Feature\Roadworks;

use App\Models\Roadwork;
use App\Roadworks\RoadworkUpserter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadworkSearchableTest extends TestCase
{
    use RefreshDatabase;

    public function test_searchable_array_reduces_linestring_geometry_to_a_geo_point(): void
    {
        $line = ['type' => 'LineString', 'coordinates' => [[5.0, 52.0], [5.2, 52.0]]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $line, 'properties' => ['causeDescription' => 'Pipe works on the N201']], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            'NDW_LINE_1',
            ['kind' => 'WORK', 'severity' => 'high', 'road_authority' => 'Provincie Utrecht', 'published' => true, 'start_date' => '2026-07-01T00:00:00Z'],
            $line,
            $doc,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        $roadwork = Roadwork::where('source_id', 'NDW_LINE_1')->firstOrFail();
        $document = $roadwork->toSearchableArray();

        // Representative point lies on the line (lat 52.0, lng between 5.0 and 5.2).
        $this->assertArrayHasKey('_geo', $document);
        $this->assertEqualsWithDelta(52.0, $document['_geo']['lat'], 0.0001);
        $this->assertGreaterThanOrEqual(5.0, $document['_geo']['lng']);
        $this->assertLessThanOrEqual(5.2, $document['_geo']['lng']);

        $this->assertSame('Provincie Utrecht', $document['road_authority']);
        $this->assertStringContainsString('Pipe works', $document['description']);
        $this->assertSame(strtotime('2026-07-01T00:00:00Z'), $document['start_ts']);
        $this->assertTrue($roadwork->shouldBeSearchable());
    }

    public function test_unpublished_roadwork_is_not_searchable(): void
    {
        $point = ['type' => 'Point', 'coordinates' => [5.1, 52.0]];
        $doc = ['situation' => ['type' => 'Feature', 'geometry' => $point, 'properties' => []], 'restrictions' => [], 'detours' => []];

        app(RoadworkUpserter::class)->upsert(
            'DATEX',
            'NDW_DRAFT_1',
            ['kind' => 'WORK', 'published' => false],
            $point,
            $doc,
            CarbonImmutable::parse('2026-06-18T10:00:00Z'),
        );

        $roadwork = Roadwork::where('source_id', 'NDW_DRAFT_1')->firstOrFail();

        $this->assertFalse($roadwork->shouldBeSearchable());
    }
}
