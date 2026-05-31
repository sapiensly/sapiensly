<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chat>
 */
class ChatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'chat_project_id' => null,
            'title' => fake()->optional()->sentence(4),
            'model' => 'claude-haiku-4-5-20251001',
            'visibility' => Visibility::Private,
            'last_message_at' => null,
            'archived_at' => null,
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
}
