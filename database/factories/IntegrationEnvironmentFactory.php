<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationEnvironment>
 */
class IntegrationEnvironmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'integration_id' => Integration::factory(),
            'name' => fake()->unique()->randomElement(['Development', 'Staging', 'Production', 'Sandbox']),
            'sort_order' => 0,
        ];
    }

    public function forIntegration(Integration $integration): static
    {
        return $this->state(fn () => ['integration_id' => $integration->id]);
    }
}
