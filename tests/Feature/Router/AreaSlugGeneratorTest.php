<?php

declare(strict_types=1);

namespace Tests\Feature\Router;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Provincie;
use App\Models\Slug;
use App\Models\Wijk;
use App\Router\AreaSlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaSlugGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_structural_slug_rows(): void
    {
        $province = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $province->id]);

        app(AreaSlugGenerator::class)->rebuild();

        $gemeenteSlug = Slug::where('sluggable_type', $gemeente->getMorphClass())
            ->where('sluggable_id', $gemeente->id)->firstOrFail();
        $this->assertSame('amsterdam', $gemeenteSlug->slug);
        $this->assertSame('noord-holland', $gemeenteSlug->parent->slug);
    }

    public function test_wijk_wins_bare_slug_buurt_gets_suffix(): void
    {
        $province = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $gemeente = Gemeente::factory()->create(['name' => 'Amsterdam', 'provincie_id' => $province->id]);
        $wijk = Wijk::factory()->create(['name' => 'Centrum', 'gemeente_id' => $gemeente->id]);
        Buurt::factory()->create(['name' => 'Centrum', 'wijk_id' => $wijk->id, 'gemeente_id' => $gemeente->id]);

        app(AreaSlugGenerator::class)->rebuild();

        $wijkSlug = Slug::where('sluggable_type', $wijk->getMorphClass())->firstOrFail();
        $buurtSlug = Slug::where('sluggable_type', (new Buurt)->getMorphClass())->firstOrFail();

        // Larger area (wijk) keeps the bare slug; the buurt is qualified to stay
        // globally unique. The exact qualifier is its parent area slug.
        $this->assertSame('centrum', $wijkSlug->slug);
        $this->assertNotSame('centrum', $buurtSlug->slug);
        $this->assertStringStartsWith('centrum-', $buurtSlug->slug);
    }

    public function test_same_named_gemeenten_get_globally_unique_slugs(): void
    {
        $nh = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $li = Provincie::factory()->create(['name' => 'Limburg']);
        Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $nh->id]);
        Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $li->id]);

        app(AreaSlugGenerator::class)->rebuild();

        $slugs = Slug::query()
            ->where('sluggable_type', (new Gemeente)->getMorphClass())
            ->where('is_current', true)
            ->pluck('slug')
            ->sort()
            ->values()
            ->all();

        // Globally unique: the first-processed keeps the bare slug, the
        // collision loser is qualified by its province.
        $this->assertSame(['bergen', 'bergen-limburg'], $slugs);
    }

    public function test_rebuild_retires_changed_slug_as_redirect_history(): void
    {
        $nh = Provincie::factory()->create(['name' => 'Noord-Holland']);
        $bergen = Gemeente::factory()->create(['name' => 'Bergen', 'provincie_id' => $nh->id]);

        app(AreaSlugGenerator::class)->rebuild();
        $this->assertDatabaseHas('slugs', [
            'slug' => 'bergen',
            'sluggable_id' => $bergen->id,
            'is_current' => true,
        ]);

        // The area is renamed; rebuild must change its slug and keep the old one
        // as a non-current redirect.
        $bergen->update(['name' => 'Bergen aan Zee']);
        app(AreaSlugGenerator::class)->rebuild();

        $this->assertDatabaseHas('slugs', [
            'slug' => 'bergen',
            'sluggable_type' => $bergen->getMorphClass(),
            'sluggable_id' => $bergen->id,
            'is_current' => false,
        ]);
        $this->assertDatabaseHas('slugs', [
            'slug' => 'bergen-aan-zee',
            'sluggable_id' => $bergen->id,
            'is_current' => true,
        ]);
    }
}
