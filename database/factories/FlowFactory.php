<?php

namespace Database\Factories;

use App\Enums\FlowStatus;
use App\Models\Agent;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flow>
 */
class FlowFactory extends Factory
{
    protected $model = Flow::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'status' => FlowStatus::Draft,
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_start',
                        'type' => 'start',
                        'position' => ['x' => 250, 'y' => 0],
                        'data' => ['trigger' => 'conversation_start'],
                    ],
                ],
                'edges' => [],
            ],
            'version' => 1,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => FlowStatus::Active,
        ]);
    }

    public function forAgent(Agent $agent): static
    {
        return $this->state(fn () => [
            'agent_id' => $agent->id,
            'user_id' => $agent->user_id,
            'organization_id' => $agent->organization_id,
        ]);
    }

    public function withSimpleMenuFlow(): static
    {
        return $this->state(fn () => [
            'definition' => [
                'nodes' => [
                    [
                        'id' => 'node_start',
                        'type' => 'start',
                        'position' => ['x' => 250, 'y' => 0],
                        'data' => ['trigger' => 'conversation_start'],
                    ],
                    [
                        'id' => 'node_menu',
                        'type' => 'menu',
                        'position' => ['x' => 250, 'y' => 150],
                        'data' => [
                            'message' => 'How can I help you?',
                            'options' => [
                                ['id' => 'option_0', 'label' => 'Check order'],
                                ['id' => 'option_1', 'label' => 'Return item'],
                                ['id' => 'option_2', 'label' => 'Other'],
                            ],
                        ],
                    ],
                    [
                        'id' => 'node_handoff_action',
                        'type' => 'agent_handoff',
                        'position' => ['x' => 50, 'y' => 350],
                        'data' => [
                            'target_agent' => 'action',
                            'context' => 'check_order',
                        ],
                    ],
                    [
                        'id' => 'node_handoff_knowledge',
                        'type' => 'agent_handoff',
                        'position' => ['x' => 450, 'y' => 350],
                        'data' => [
                            'target_agent' => 'knowledge',
                        ],
                    ],
                    [
                        'id' => 'node_message',
                        'type' => 'message',
                        'position' => ['x' => 250, 'y' => 350],
                        'data' => [
                            'message' => 'Let me help you with your return.',
                        ],
                    ],
                ],
                'edges' => [
                    ['id' => 'e_start_menu', 'source' => 'node_start', 'target' => 'node_menu'],
                    ['id' => 'e_menu_action', 'source' => 'node_menu', 'target' => 'node_handoff_action', 'sourceHandle' => 'option_0'],
                    ['id' => 'e_menu_message', 'source' => 'node_menu', 'target' => 'node_message', 'sourceHandle' => 'option_1'],
                    ['id' => 'e_menu_knowledge', 'source' => 'node_menu', 'target' => 'node_handoff_knowledge', 'sourceHandle' => 'option_2'],
                ],
            ],
        ]);
    }
}
