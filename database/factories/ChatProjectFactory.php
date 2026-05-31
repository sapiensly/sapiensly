<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\ChatProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatProject>
 */
class ChatProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'custom_instructions' => fake()->optional()->paragraph(),
            'visibility' => Visibility::Private,
        ];
    }
}
