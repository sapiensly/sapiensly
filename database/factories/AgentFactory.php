<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Models\AgentTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'agent_team_id' => AgentTeam::factory(),
            'type' => fake()->randomElement(AgentType::cases()),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'status' => AgentStatus::Draft,
            'prompt_template' => null,
            'model' => 'claude-sonnet-4-20250514',
            'config' => null,
        ];
    }

    public function standalone(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => User::factory(),
            'agent_team_id' => null,
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'agent_team_id' => null,
        ]);
    }

    public function triage(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AgentType::Triage,
            'name' => 'Triage Agent',
        ]);
    }

    public function knowledge(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AgentType::Knowledge,
            'name' => 'Knowledge Agent',
        ]);
    }

    public function action(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => AgentType::Action,
            'name' => 'Action Agent',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentStatus::Active,
        ]);
    }
}
