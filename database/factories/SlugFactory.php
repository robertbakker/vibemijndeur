<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Gemeente;
use App\Models\Slug;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Slug> */
class SlugFactory extends Factory
{
    protected $model = Slug::class;

    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'sluggable_type' => (new Gemeente)->getMorphClass(),
            'sluggable_id' => Gemeente::factory(),
            'parent_id' => null,
            'is_current' => true,
        ];
    }

    public function historical(): self
    {
        return $this->state(['is_current' => false]);
    }
}
