<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebateParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateParticipant>
 */
class DebateParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'debate_id' => Debate::factory(),
            'model' => 'claude-haiku-4-5-20251001',
            'provider' => 'Anthropic',
            'display_name' => fake()->randomElement(['Claude Haiku', 'GPT-4o', 'Gemini Pro']),
            'position' => fake()->numberBetween(0, 4),
            'accent' => 'violet',
            'final_stance' => null,
        ];
    }
}
