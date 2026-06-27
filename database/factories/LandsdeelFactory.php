<?php

namespace Database\Factories;

use App\Models\Landsdeel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Landsdeel>
 */
class LandsdeelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'LD'.fake()->unique()->numerify('##'),
            'name' => fake()->city(),
            'year' => 2024,
            'geometry' => DB::raw("ST_Multi(ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326))"),
        ];
    }
}
