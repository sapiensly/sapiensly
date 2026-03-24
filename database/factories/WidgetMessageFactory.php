<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetMessage>
 */
class WidgetMessageFactory extends Factory
{
    protected $model = WidgetMessage::class;

    public function definition(): array
    {
        return [
            'widget_conversation_id' => WidgetConversation::factory(),
            'role' => fake()->randomElement(MessageRole::cases()),
            'content' => fake()->paragraph(),
            'tokens_used' => null,
            'model' => null,
            'metadata' => null,
            'response_time_ms' => null,
        ];
    }

    public function user(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::User,
        ]);
    }

    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::Assistant,
            'model' => 'claude-sonnet-4-20250514',
            'response_time_ms' => fake()->numberBetween(500, 5000),
        ]);
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => MessageRole::System,
        ]);
    }
}
