<?php

namespace Database\Factories;

use App\Models\Landsdeel;
use App\Models\Provincie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Provincie>
 */
class ProvincieFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PV'.fake()->unique()->numerify('##'),
            'name' => fake()->city(),
            'year' => 2024,
            'landsdeel_id' => Landsdeel::factory(),
            'geometry' => DB::raw("ST_Multi(ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326))"),
        ];
    }
}
