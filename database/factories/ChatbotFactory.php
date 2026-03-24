<?php

namespace Database\Factories;

use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chatbot>
 */
class ChatbotFactory extends Factory
{
    protected $model = Chatbot::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'organization_id' => null,
            'visibility' => Visibility::Private,
            'agent_id' => Agent::factory()->standalone(),
            'agent_team_id' => null,
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'status' => ChatbotStatus::Draft,
            'config' => Chatbot::getDefaultConfig(),
            'allowed_origins' => null,
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withAgent(Agent $agent): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => $agent->id,
            'agent_team_id' => null,
        ]);
    }

    public function withAgentTeam(AgentTeam $team): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => null,
            'agent_team_id' => $team->id,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatbotStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatbotStatus::Inactive,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChatbotStatus::Draft,
        ]);
    }

    public function withAllowedOrigins(array $origins): static
    {
        return $this->state(fn (array $attributes) => [
            'allowed_origins' => $origins,
        ]);
    }
}
