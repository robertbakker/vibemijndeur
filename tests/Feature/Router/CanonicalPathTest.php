<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Gemeente;
use App\Models\Slug;
use App\Router\CanonicalPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_gemeente_collapses_to_bare_slug(): void
    {
        $nh = Slug::factory()->create(['slug' => 'noord-holland', 'parent_id' => null]);
        $ams = Slug::factory()->create([
            'slug' => 'amsterdam', 'parent_id' => $nh->id,
            'sluggable_id' => Gemeente::factory(),
        ]);

        $this->assertSame('amsterdam', CanonicalPath::for($ams));
    }

    public function test_ambiguous_gemeente_keeps_province_prefix(): void
    {
        $nh = Slug::factory()->create(['slug' => 'noord-holland', 'parent_id' => null]);
        $li = Slug::factory()->create(['slug' => 'limburg', 'parent_id' => null]);
        $bergenNh = Slug::factory()->create([
            'slug' => 'bergen', 'parent_id' => $nh->id, 'sluggable_id' => Gemeente::factory(),
        ]);
        Slug::factory()->create([
            'slug' => 'bergen', 'parent_id' => $li->id, 'sluggable_id' => Gemeente::factory(),
        ]);

        $this->assertSame('noord-holland/bergen', CanonicalPath::for($bergenNh));
    }
}
