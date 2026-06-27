<?php

namespace Database\Factories;

use App\Models\Gemeente;
use App\Models\Provincie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Gemeente>
 */
class GemeenteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'GM'.fake()->unique()->numerify('####'),
            'name' => fake()->city(),
            'year' => 2024,
            'provincie_id' => Provincie::factory(),
            'geometry' => DB::raw("ST_Multi(ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326))"),
        ];
    }
}
