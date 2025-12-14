<?php

namespace Database\Factories;

use App\Enums\KnowledgeBaseStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KnowledgeBase>
 */
class KnowledgeBaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => KnowledgeBaseStatus::Pending,
            'config' => [
                'chunk_size' => 1000,
                'chunk_overlap' => 200,
            ],
            'document_count' => 0,
            'chunk_count' => 0,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeBaseStatus::Ready,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeBaseStatus::Processing,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => KnowledgeBaseStatus::Failed,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
