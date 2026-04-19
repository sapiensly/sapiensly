<?php

namespace Database\Factories;

use App\Enums\IntegrationAuthType;
use App\Enums\Visibility;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'visibility' => Visibility::Private,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => fake()->optional()->sentence(),
            'base_url' => fake()->url(),
            'auth_type' => IntegrationAuthType::None,
            'auth_config' => [],
            'default_headers' => null,
            'status' => 'active',
            'allow_insecure_tls' => false,
        ];
    }

    public function apiKey(): static
    {
        return $this->state(fn () => [
            'auth_type' => IntegrationAuthType::ApiKey,
            'auth_config' => ['location' => 'header', 'name' => 'X-Api-Key', 'value' => 'sk-test-'.fake()->sha256()],
        ]);
    }

    public function bearer(): static
    {
        return $this->state(fn () => [
            'auth_type' => IntegrationAuthType::BearerToken,
            'auth_config' => ['token' => 'bearer-'.fake()->sha256()],
        ]);
    }

    public function basicAuth(): static
    {
        return $this->state(fn () => [
            'auth_type' => IntegrationAuthType::BasicAuth,
            'auth_config' => ['username' => fake()->userName(), 'password' => fake()->password()],
        ]);
    }

    public function oauth2ClientCreds(): static
    {
        return $this->state(fn () => [
            'auth_type' => IntegrationAuthType::OAuth2ClientCredentials,
            'auth_config' => [
                'token_url' => 'https://auth.example.com/oauth/token',
                'client_id' => 'client_'.fake()->uuid(),
                'client_secret' => fake()->sha256(),
                'scope' => 'read:all',
                'audience' => null,
            ],
        ]);
    }

    public function oauth2AuthCode(): static
    {
        return $this->state(fn () => [
            'auth_type' => IntegrationAuthType::OAuth2AuthorizationCode,
            'auth_config' => [
                'authorize_url' => 'https://auth.example.com/oauth/authorize',
                'token_url' => 'https://auth.example.com/oauth/token',
                'client_id' => 'client_'.fake()->uuid(),
                'client_secret' => fake()->sha256(),
                'redirect_uri' => 'https://sapiensly.test/oauth/integrations/callback',
                'scope' => 'openid profile email',
                'pkce' => true,
            ],
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
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

    public function global(): static
    {
        return $this->state(fn () => [
            'user_id' => null,
            'organization_id' => null,
            'visibility' => Visibility::Global,
        ]);
    }
}
