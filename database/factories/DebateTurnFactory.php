<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebateParticipant;
use App\Models\DebateRound;
use App\Models\DebateTurn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateTurn>
 */
class DebateTurnFactory extends Factory
{
    public function definition(): array
    {
        return [
            'debate_id' => Debate::factory(),
            'debate_round_id' => DebateRound::factory(),
            'debate_participant_id' => DebateParticipant::factory(),
            'role' => 'participant',
            'model' => 'claude-haiku-4-5-20251001',
            'content' => fake()->paragraph(),
            'stance_summary' => fake()->sentence(),
            'status' => 'complete',
            'error' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['content' => null, 'stance_summary' => null, 'status' => 'pending']);
    }

    public function streaming(): static
    {
        return $this->state(fn () => ['content' => null, 'status' => 'streaming']);
    }

    public function complete(): static
    {
        return $this->state(fn () => ['status' => 'complete']);
    }

    public function error(): static
    {
        return $this->state(fn () => ['content' => null, 'status' => 'error', 'error' => 'Something went wrong.']);
    }

    public function moderator(): static
    {
        return $this->state(fn () => ['role' => 'moderator', 'debate_participant_id' => null]);
    }
}
