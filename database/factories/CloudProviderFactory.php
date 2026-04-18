<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudProvider>
 */
class CloudProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'visibility' => Visibility::Private,
            'kind' => 'storage',
            'driver' => 's3',
            'display_name' => 'Amazon S3',
            'credentials' => [
                'bucket' => 'test-bucket',
                'region' => 'us-east-1',
                'key' => 'AKIA'.fake()->regexify('[A-Z0-9]{16}'),
                'secret' => fake()->sha256(),
            ],
            'is_default' => true,
            'status' => 'active',
        ];
    }

    public function storage(): static
    {
        return $this->state(fn () => [
            'kind' => 'storage',
            'driver' => 's3',
            'display_name' => 'Amazon S3',
            'credentials' => [
                'bucket' => 'test-bucket',
                'region' => 'us-east-1',
                'key' => 'AKIA'.fake()->regexify('[A-Z0-9]{16}'),
                'secret' => fake()->sha256(),
            ],
        ]);
    }

    public function postgres(): static
    {
        return $this->state(fn () => [
            'kind' => 'database',
            'driver' => 'postgresql',
            'display_name' => 'PostgreSQL',
            'credentials' => [
                'host' => '127.0.0.1',
                'port' => '5432',
                'database' => 'tenant_db',
                'username' => 'tenant',
                'password' => fake()->password(),
                'sslmode' => 'prefer',
            ],
        ]);
    }

    public function global(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'organization_id' => null,
            'visibility' => Visibility::Global,
        ]);
    }

    public function forOrganization(Organization $organization, ?User $user = null): static
    {
        return $this->state(fn () => [
            'user_id' => $user?->id ?? User::factory(),
            'organization_id' => $organization->id,
            'visibility' => Visibility::Organization,
        ]);
    }
}
