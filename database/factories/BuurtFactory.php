<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Buurt;
use App\Models\Gemeente;
use App\Models\Wijk;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Buurt>
 */
class BuurtFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'BU'.fake()->unique()->numerify('########'),
            'name' => fake()->streetName(),
            'year' => 2024,
            'wijk_id' => Wijk::factory(),
            'gemeente_id' => Gemeente::factory(),
            'geometry' => DB::raw("ST_Multi(ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 1,0 0))', 4326))"),
        ];
    }
}
