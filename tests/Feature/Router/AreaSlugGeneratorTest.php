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

        $this->assertSame('centrum', $wijkSlug->slug);
        $this->assertSame('centrum-buurt', $buurtSlug->slug);
    }
}
