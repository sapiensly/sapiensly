<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationRequest>
 */
class IntegrationRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'integration_id' => Integration::factory(),
            'user_id' => null,
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'folder' => null,
            'method' => 'GET',
            'path' => '/'.fake()->slug(2),
            'query_params' => [],
            'headers' => [],
            'body_type' => null,
            'body_content' => null,
            'timeout_ms' => 30000,
            'follow_redirects' => true,
            'sort_order' => 0,
        ];
    }

    public function post(): static
    {
        return $this->state(fn () => [
            'method' => 'POST',
            'body_type' => 'json',
            'body_content' => '{"hello": "world"}',
        ]);
    }

    public function forIntegration(Integration $integration): static
    {
        return $this->state(fn () => ['integration_id' => $integration->id]);
    }
}
