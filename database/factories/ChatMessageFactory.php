<?php

namespace Database\Factories;

use App\Models\Chat;
use App\Models\ChatMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'chat_id' => Chat::factory(),
            'role' => 'user',
            'content' => fake()->sentence(),
            'model' => null,
            'status' => 'complete',
            'error' => null,
        ];
    }

    public function assistant(): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'model' => 'claude-haiku-4-5-20251001',
        ]);
    }

    public function streaming(): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'content' => null,
            'status' => 'streaming',
        ]);
    }

    public function error(): static
    {
        return $this->state(fn () => [
            'role' => 'assistant',
            'content' => null,
            'status' => 'error',
            'error' => 'Something went wrong.',
        ]);
    }
}
