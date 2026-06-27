<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Roadwork;
use App\Models\Slug;
use App\Router\SegmentCursor;
use App\Router\Segments\RoadworkSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkSegmentTest extends TestCase
{
    use RefreshDatabase;

    private function roadwork(string $sourceId): int
    {
        return DB::table('roadworks')->insertGetId(
            ['source' => 'X', 'source_id' => $sourceId, 'feature' => '{}'], 'id'
        );
    }

    private function morph(): string
    {
        return (new Roadwork)->getMorphClass();
    }

    public function test_it_resolves_a_current_roadwork_slug(): void
    {
        $id = $this->roadwork('A');
        Slug::factory()->create([
            'slug' => 'a4-knooppunt-x', 'parent_id' => null, 'is_current' => true,
            'sluggable_type' => $this->morph(), 'sluggable_id' => $id,
        ]);

        $resolution = (new RoadworkSegment)->resolve(new SegmentCursor(['a4-knooppunt-x']));

        $this->assertNotNull($resolution);
        $this->assertSame($id, $resolution->roadworkId);
        $this->assertNull($resolution->redirectToSlug);
    }

    public function test_a_historical_slug_redirects_to_current(): void
    {
        $id = $this->roadwork('B');
        Slug::factory()->create([
            'slug' => 'a4-current', 'parent_id' => null, 'is_current' => true,
            'sluggable_type' => $this->morph(), 'sluggable_id' => $id,
        ]);
        Slug::factory()->historical()->create([
            'slug' => 'a4-old', 'parent_id' => null,
            'sluggable_type' => $this->morph(), 'sluggable_id' => $id,
        ]);

        $resolution = (new RoadworkSegment)->resolve(new SegmentCursor(['a4-old']));

        $this->assertSame('a4-current', $resolution->redirectToSlug);
    }

    public function test_unknown_slug_returns_null(): void
    {
        $this->assertNull((new RoadworkSegment)->resolve(new SegmentCursor(['nope'])));
    }
}
