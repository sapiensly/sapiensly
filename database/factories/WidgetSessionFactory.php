<?php

namespace Database\Factories;

use App\Models\Chatbot;
use App\Models\WidgetSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetSession>
 */
class WidgetSessionFactory extends Factory
{
    protected $model = WidgetSession::class;

    public function definition(): array
    {
        return [
            'chatbot_id' => Chatbot::factory(),
            'session_token' => WidgetSession::generateSessionToken(),
            'visitor_email' => fake()->optional()->email(),
            'visitor_name' => fake()->optional()->name(),
            'visitor_metadata' => [],
            'user_agent' => fake()->userAgent(),
            'ip_address' => fake()->ipv4(),
            'referrer_url' => fake()->optional()->url(),
            'page_url' => fake()->url(),
            'last_activity_at' => now(),
        ];
    }

    public function identified(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_email' => fake()->email(),
            'visitor_name' => fake()->name(),
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'visitor_email' => null,
            'visitor_name' => null,
        ]);
    }
}
