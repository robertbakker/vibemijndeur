<?php

declare(strict_types=1);

namespace Tests\Feature\Areas;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Landsdeel;
use App\Models\Provincie;
use App\Models\Roadwork;
use App\Models\Wijk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoadworkAreaRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_roadwork_exposes_each_area_level(): void
    {
        $roadwork = $this->roadwork();
        $gemeente = Gemeente::factory()->create();
        $buurt = Buurt::factory()->create();
        $roadwork->gemeenten()->attach($gemeente);
        $roadwork->buurten()->attach($buurt);

        $this->assertTrue($roadwork->gemeenten->contains($gemeente));
        $this->assertTrue($roadwork->buurten->contains($buurt));
    }

    public function test_areas_expose_their_roadworks(): void
    {
        $roadwork = $this->roadwork();
        $landsdeel = Landsdeel::factory()->create();
        $provincie = Provincie::factory()->create();
        $wijk = Wijk::factory()->create();
        $roadwork->landsdelen()->attach($landsdeel);
        $roadwork->provincies()->attach($provincie);
        $roadwork->wijken()->attach($wijk);

        $this->assertTrue($landsdeel->roadworks->contains($roadwork));
        $this->assertTrue($provincie->roadworks->contains($roadwork));
        $this->assertTrue($wijk->roadworks->contains($roadwork));
    }

    private function roadwork(): Roadwork
    {
        $id = (int) DB::selectOne(
            "INSERT INTO roadworks (source, source_id, feature) VALUES ('test', ?, '{}'::jsonb) RETURNING id",
            [uniqid()],
        )->id;

        return Roadwork::findOrFail($id);
    }
}
