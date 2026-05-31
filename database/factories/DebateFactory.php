<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Debate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debate>
 */
class DebateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'title' => fake()->optional()->sentence(4),
            'topic' => fake()->sentence(12),
            'status' => 'pending',
            'max_rounds' => 3,
            'current_round' => 0,
            'moderator_model' => 'claude-haiku-4-5-20251001',
            'consensus_reached' => false,
            'consensus_score' => null,
            'settings' => null,
            'visibility' => Visibility::Private,
            'last_activity_at' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
        ]);
    }

    public function debating(): static
    {
        return $this->state(fn () => [
            'status' => 'debating',
            'current_round' => 1,
        ]);
    }
}
