<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentTeam>
 */
class AgentTeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => AgentStatus::Draft,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Inactive,
        ]);
    }
}
