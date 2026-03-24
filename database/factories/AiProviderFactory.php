<?php

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProvider>
 */
class AiProviderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'anthropic',
            'driver' => 'anthropic',
            'display_name' => 'Anthropic',
            'credentials' => ['api_key' => 'sk-test-'.fake()->sha256()],
            'models' => [
                ['id' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4', 'capabilities' => ['chat']],
                ['id' => 'claude-3-5-haiku-20241022', 'label' => 'Claude 3.5 Haiku', 'capabilities' => ['chat']],
            ],
            'is_default' => false,
            'is_default_embeddings' => false,
            'status' => 'active',
        ];
    }

    public function anthropic(): static
    {
        return $this->state(fn () => [
            'name' => 'anthropic',
            'driver' => 'anthropic',
            'display_name' => 'Anthropic',
            'models' => [
                ['id' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4', 'capabilities' => ['chat']],
                ['id' => 'claude-opus-4-20250514', 'label' => 'Claude Opus 4', 'capabilities' => ['chat']],
                ['id' => 'claude-3-5-haiku-20241022', 'label' => 'Claude 3.5 Haiku', 'capabilities' => ['chat']],
            ],
        ]);
    }

    public function openai(): static
    {
        return $this->state(fn () => [
            'name' => 'openai',
            'driver' => 'openai',
            'display_name' => 'OpenAI',
            'credentials' => ['api_key' => 'sk-test-'.fake()->sha256()],
            'models' => [
                ['id' => 'gpt-4o', 'label' => 'GPT-4o', 'capabilities' => ['chat']],
                ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'capabilities' => ['chat']],
                ['id' => 'text-embedding-3-small', 'label' => 'Embedding 3 Small', 'capabilities' => ['embeddings']],
                ['id' => 'text-embedding-3-large', 'label' => 'Embedding 3 Large', 'capabilities' => ['embeddings']],
            ],
        ]);
    }

    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }

    public function defaultEmbeddings(): static
    {
        return $this->state(fn () => [
            'is_default_embeddings' => true,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => 'inactive',
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? 'organization' : 'private',
        ]);
    }
}
