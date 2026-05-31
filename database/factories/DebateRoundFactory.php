<?php

namespace Database\Factories;

use App\Models\Debate;
use App\Models\DebateRound;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateRound>
 */
class DebateRoundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'debate_id' => Debate::factory(),
            'round_number' => 1,
            'type' => 'opening',
            'status' => 'pending',
            'consensus_score' => null,
            'consensus_summary' => null,
            'consensus_reached' => false,
        ];
    }

    public function opening(): static
    {
        return $this->state(fn () => ['type' => 'opening', 'round_number' => 1]);
    }

    public function rebuttal(): static
    {
        return $this->state(fn () => ['type' => 'rebuttal', 'round_number' => 2]);
    }

    public function synthesis(): static
    {
        return $this->state(fn () => ['type' => 'synthesis']);
    }
}
