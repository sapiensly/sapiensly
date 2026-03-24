<?php

namespace Database\Factories;

use App\Models\Chatbot;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetConversation>
 */
class WidgetConversationFactory extends Factory
{
    protected $model = WidgetConversation::class;

    public function definition(): array
    {
        return [
            'chatbot_id' => Chatbot::factory(),
            'widget_session_id' => WidgetSession::factory(),
            'title' => fake()->optional()->sentence(3),
            'metadata' => [],
            'message_count' => 0,
            'rating' => null,
            'feedback' => null,
            'first_response_at' => null,
            'total_response_time_ms' => 0,
            'is_resolved' => false,
            'is_abandoned' => false,
            'abandoned_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_resolved' => true,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_abandoned' => true,
            'abandoned_at' => now(),
        ]);
    }

    public function withRating(int $rating): static
    {
        return $this->state(fn (array $attributes) => [
            'rating' => $rating,
            'is_resolved' => true,
        ]);
    }
}
