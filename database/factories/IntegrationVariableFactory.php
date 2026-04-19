<?php

namespace Database\Factories;

use App\Models\IntegrationEnvironment;
use App\Models\IntegrationVariable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationVariable>
 */
class IntegrationVariableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'integration_environment_id' => IntegrationEnvironment::factory(),
            'key' => fake()->unique()->slug(2, false),
            'value' => fake()->word(),
            'is_secret' => false,
            'description' => fake()->optional()->sentence(),
        ];
    }

    public function secret(): static
    {
        return $this->state(fn () => [
            'is_secret' => true,
            'value' => 'secret-'.fake()->sha256(),
        ]);
    }

    public function forEnvironment(IntegrationEnvironment $environment): static
    {
        return $this->state(fn () => ['integration_environment_id' => $environment->id]);
    }
}
