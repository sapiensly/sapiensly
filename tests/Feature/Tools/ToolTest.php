<?php

use App\Enums\AgentStatus;
use App\Enums\ToolType;
use App\Models\Tool;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('index', function () {
    it('displays the tools index page', function () {
        $this->actingAs($this->user)
            ->get(route('tools.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('tools/Index'));
    });

    it('shows only tools belonging to the authenticated user', function () {
        $myTool = Tool::factory()->create(['user_id' => $this->user->id]);
        $otherTool = Tool::factory()->create();

        $this->actingAs($this->user)
            ->get(route('tools.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tools/Index')
                ->has('tools.data', 1)
                ->where('tools.data.0.id', $myTool->id)
            );
    });

    it('filters tools by type', function () {
        Tool::factory()->function()->create(['user_id' => $this->user->id]);
        Tool::factory()->mcp()->create(['user_id' => $this->user->id]);
        Tool::factory()->group()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('tools.index', ['type' => 'function']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tools.data', 1)
                ->where('tools.data.0.type', 'function')
            );
    });

    it('returns tool counts by type', function () {
        Tool::factory()->function()->count(2)->create(['user_id' => $this->user->id]);
        Tool::factory()->mcp()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('tools.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('toolsByType.function', 2)
                ->where('toolsByType.mcp', 1)
            );
    });
});

describe('create', function () {
    it('displays the create tool form', function () {
        $this->actingAs($this->user)
            ->get(route('tools.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tools/Create')
                ->has('toolTypes', 3)
            );
    });

    it('accepts type query parameter', function () {
        $this->actingAs($this->user)
            ->get(route('tools.create', ['type' => 'mcp']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedType', 'mcp')
            );
    });

    it('provides available tools for group creation', function () {
        Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);
        Tool::factory()->mcp()->active()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('tools.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('availableTools', 2)
            );
    });
});

describe('store', function () {
    it('creates a function tool', function () {
        $data = [
            'type' => ToolType::Function->value,
            'name' => 'Get Weather',
            'description' => 'Gets weather for a location',
            'config' => [
                'name' => 'get_weather',
                'description' => 'Get current weather',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'location' => [
                            'type' => 'string',
                            'description' => 'City name',
                        ],
                    ],
                    'required' => ['location'],
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('tools.store'), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('tools', [
            'user_id' => $this->user->id,
            'name' => 'Get Weather',
            'type' => ToolType::Function->value,
            'status' => AgentStatus::Draft->value,
        ]);
    });

    it('creates an MCP tool', function () {
        $data = [
            'type' => ToolType::Mcp->value,
            'name' => 'External API',
            'description' => 'Connects to external API',
            'config' => [
                'endpoint' => 'https://api.example.com/mcp',
                'auth_type' => 'bearer',
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('tools.store'), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('tools', [
            'user_id' => $this->user->id,
            'name' => 'External API',
            'type' => ToolType::Mcp->value,
        ]);
    });

    it('creates a tool group with members', function () {
        $tool1 = Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);
        $tool2 = Tool::factory()->mcp()->active()->create(['user_id' => $this->user->id]);

        $data = [
            'type' => ToolType::Group->value,
            'name' => 'My Tool Group',
            'description' => 'A collection of tools',
            'tool_ids' => [$tool1->id, $tool2->id],
        ];

        $this->actingAs($this->user)
            ->post(route('tools.store'), $data)
            ->assertRedirect();

        $group = Tool::where('name', 'My Tool Group')->first();
        expect($group->groupItems)->toHaveCount(2);
        expect($group->groupItems->pluck('tool_id')->toArray())->toContain($tool1->id, $tool2->id);
    });

    it('validates required fields', function () {
        $this->actingAs($this->user)
            ->post(route('tools.store'), [])
            ->assertSessionHasErrors(['type', 'name']);
    });
});

describe('show', function () {
    it('displays a function tool', function () {
        $tool = Tool::factory()->function()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('tools.show', $tool))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tools/Show')
                ->where('tool.id', $tool->id)
                ->where('tool.type', 'function')
            );
    });

    it('displays a tool group with its members', function () {
        $group = Tool::factory()->group()->create(['user_id' => $this->user->id]);
        $tool = Tool::factory()->function()->create(['user_id' => $this->user->id]);
        $group->groupItems()->create(['tool_id' => $tool->id, 'order' => 0]);

        $this->actingAs($this->user)
            ->get(route('tools.show', $group))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tool.id', $group->id)
                ->has('tool.group_items', 1)
            );
    });

    it('returns 403 for tools belonging to other users', function () {
        $otherTool = Tool::factory()->create();

        $this->actingAs($this->user)
            ->get(route('tools.show', $otherTool))
            ->assertForbidden();
    });
});

describe('edit', function () {
    it('displays the edit form for a tool', function () {
        $tool = Tool::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('tools.edit', $tool))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('tools/Edit')
                ->where('tool.id', $tool->id)
            );
    });

    it('provides available tools for group editing excluding self', function () {
        $group = Tool::factory()->group()->create(['user_id' => $this->user->id]);
        $tool = Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('tools.edit', $group))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('availableTools', 1)
                ->where('availableTools.0.id', $tool->id)
            );
    });

    it('returns 403 for tools belonging to other users', function () {
        $otherTool = Tool::factory()->create();

        $this->actingAs($this->user)
            ->get(route('tools.edit', $otherTool))
            ->assertForbidden();
    });
});

describe('update', function () {
    it('updates a tool', function () {
        $tool = Tool::factory()->function()->create(['user_id' => $this->user->id]);

        $data = [
            'name' => 'Updated Tool Name',
            'description' => 'Updated description',
            'status' => AgentStatus::Active->value,
            'config' => [
                'name' => 'updated_function',
            ],
        ];

        $this->actingAs($this->user)
            ->put(route('tools.update', $tool), $data)
            ->assertRedirect();

        $tool->refresh();
        expect($tool->name)->toBe('Updated Tool Name');
        expect($tool->status)->toBe(AgentStatus::Active);
    });

    it('updates tool group members', function () {
        $group = Tool::factory()->group()->create(['user_id' => $this->user->id]);
        $tool1 = Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);
        $tool2 = Tool::factory()->function()->active()->create(['user_id' => $this->user->id]);
        $group->groupItems()->create(['tool_id' => $tool1->id, 'order' => 0]);

        $data = [
            'name' => $group->name,
            'tool_ids' => [$tool2->id],
        ];

        $this->actingAs($this->user)
            ->put(route('tools.update', $group), $data)
            ->assertRedirect();

        $group->refresh();
        expect($group->groupItems)->toHaveCount(1);
        expect($group->groupItems->first()->tool_id)->toBe($tool2->id);
    });

    it('returns 403 when updating tools belonging to other users', function () {
        $otherTool = Tool::factory()->create();

        $this->actingAs($this->user)
            ->put(route('tools.update', $otherTool), [
                'name' => 'Hacked',
            ])
            ->assertForbidden();
    });
});

describe('destroy', function () {
    it('soft deletes a tool', function () {
        $tool = Tool::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->delete(route('tools.destroy', $tool))
            ->assertRedirect(route('tools.index'));

        $this->assertSoftDeleted('tools', ['id' => $tool->id]);
    });

    it('returns 403 when deleting tools belonging to other users', function () {
        $otherTool = Tool::factory()->create();

        $this->actingAs($this->user)
            ->delete(route('tools.destroy', $otherTool))
            ->assertForbidden();
    });
});
