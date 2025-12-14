<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tool>
 */
class ToolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(ToolType::cases()),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'config' => [],
            'status' => AgentStatus::Draft,
            'is_validated' => false,
            'last_validated_at' => null,
        ];
    }

    public function function(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ToolType::Function,
            'config' => [
                'name' => fake()->word(),
                'description' => fake()->sentence(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'input' => [
                            'type' => 'string',
                            'description' => 'The input value',
                        ],
                    ],
                    'required' => ['input'],
                ],
            ],
        ]);
    }

    public function mcp(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ToolType::Mcp,
            'config' => [
                'endpoint' => fake()->url(),
                'auth_type' => 'bearer',
                'auth_config' => [],
            ],
        ]);
    }

    public function group(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ToolType::Group,
            'config' => [],
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Active,
        ]);
    }

    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_validated' => true,
            'last_validated_at' => now(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
