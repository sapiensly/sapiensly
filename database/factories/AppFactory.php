<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'organization_id' => null,
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'icon' => null,
            'color' => null,
            'current_version_id' => null,
            'visibility' => Visibility::Private,
        ];
    }
}
