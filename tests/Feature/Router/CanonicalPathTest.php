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

    public function test_globally_unique_qualified_slug_returns_itself(): void
    {
        // Slugs are globally unique now (AreaSlugGenerator qualifies collisions),
        // so the canonical path is always the single slug segment — never nested.
        $nh = Slug::factory()->create(['slug' => 'noord-holland', 'parent_id' => null]);
        $bergen = Slug::factory()->create([
            'slug' => 'bergen-limburg', 'parent_id' => $nh->id,
            'sluggable_id' => Gemeente::factory(),
        ]);

        $this->assertSame('bergen-limburg', CanonicalPath::for($bergen));
    }
}
