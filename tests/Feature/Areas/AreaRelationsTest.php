<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Landsdeel;
use App\Models\Provincie;
use App\Models\Wijk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tree_walks_from_buurt_up_to_landsdeel(): void
    {
        $landsdeel = Landsdeel::factory()->create();
        $provincie = Provincie::factory()->for($landsdeel)->create();
        $gemeente = Gemeente::factory()->for($provincie)->create();
        $wijk = Wijk::factory()->for($gemeente)->create();
        $buurt = Buurt::factory()->for($wijk)->for($gemeente)->create();

        $this->assertTrue($buurt->wijk->is($wijk));
        $this->assertTrue($buurt->gemeente->is($gemeente));
        $this->assertTrue($wijk->gemeente->provincie->landsdeel->is($landsdeel));
    }

    public function test_parents_expose_their_children(): void
    {
        $gemeente = Gemeente::factory()->create();
        Wijk::factory()->count(2)->for($gemeente)->create();

        $this->assertCount(2, $gemeente->refresh()->wijken);
        $this->assertSame(1, $gemeente->provincie->landsdeel->provincies->count());
    }
}
