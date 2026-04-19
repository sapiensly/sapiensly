<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationExecution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationExecution>
 */
class IntegrationExecutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'integration_id' => Integration::factory(),
            'integration_request_id' => null,
            'environment_id' => null,
            'user_id' => null,
            'organization_id' => null,
            'method' => 'GET',
            'url' => fake()->url(),
            'request_headers' => [],
            'request_body' => null,
            'response_status' => 200,
            'response_headers' => ['content-type' => 'application/json'],
            'response_body' => '{"ok":true}',
            'response_size_bytes' => 11,
            'duration_ms' => fake()->numberBetween(50, 500),
            'success' => true,
            'error_message' => null,
            'metadata' => ['invoked_by' => 'user'],
        ];
    }

    public function successful(): static
    {
        return $this->state(fn () => ['success' => true, 'response_status' => 200]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'success' => false,
            'response_status' => 500,
            'error_message' => 'Server error',
        ]);
    }

    public function forIntegration(Integration $integration): static
    {
        return $this->state(fn () => [
            'integration_id' => $integration->id,
            'organization_id' => $integration->organization_id,
        ]);
    }
}
