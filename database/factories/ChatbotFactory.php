<?php

namespace Database\Factories;

use App\Enums\BotFlowStatus;
use App\Enums\ChatbotStatus;
use App\Enums\Visibility;
use App\Models\Agent;
use App\Models\BotFlow;
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

    /**
     * Give the bot a Bot Flow whose single agent node is bound to $agent,
     * so the runtime resolves a one-agent roster (direct chat).
     */
    public function withBotFlow(Agent $agent): static
    {
        return $this->afterCreating(function (Chatbot $chatbot) use ($agent) {
            BotFlow::factory()->create([
                'user_id' => $chatbot->user_id,
                'organization_id' => $chatbot->organization_id,
                'chatbot_id' => $chatbot->id,
                'status' => BotFlowStatus::Active,
                'definition' => [
                    'nodes' => [
                        ['id' => 'start', 'type' => 'start', 'position' => ['x' => 250, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                        ['id' => 'agent_triage', 'type' => 'agent', 'position' => ['x' => 620, 'y' => 0], 'data' => ['role' => 'triage', 'agent_id' => $agent->id, 'agent_name' => $agent->name]],
                    ],
                    'edges' => [],
                ],
            ]);
        });
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
