<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tool>
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

    public function restApi(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ToolType::RestApi,
            'config' => [
                'base_url' => fake()->url(),
                'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
                'path' => '/api/v1/'.fake()->word(),
                'headers' => ['Content-Type' => 'application/json'],
                'auth_type' => 'bearer',
                'auth_config' => [],
                'request_body_template' => '{"id": "{{id}}"}',
            ],
        ]);
    }

    public function graphql(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ToolType::Graphql,
            'config' => [
                'endpoint' => fake()->url().'/graphql',
                'operation_type' => fake()->randomElement(['query', 'mutation']),
                'operation' => 'query GetItem($id: ID!) { item(id: $id) { id name } }',
                'variables_template' => ['id' => '{{id}}'],
                'auth_type' => 'bearer',
                'auth_config' => [],
            ],
        ]);
    }

    public function database(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ToolType::Database,
            'config' => [
                'driver' => fake()->randomElement(['pgsql', 'mysql']),
                'host' => 'localhost',
                'port' => 5432,
                'database' => fake()->word().'_db',
                'username' => fake()->userName(),
                'password' => fake()->password(),
                'query_template' => 'SELECT * FROM items WHERE id = :id',
                'read_only' => true,
            ],
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
