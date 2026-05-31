<?php

use App\Ai\Tools\McpServerTool;
use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\Tool;
use App\Models\User;
use App\Services\Tools\McpClient;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function () {
    $this->user = User::factory()->create();
    AiProvider::factory()->anthropic()->forUser($this->user)->create(['status' => 'active']);
});

it('persists accessible tool ids on the chat and passes them to the job', function () {
    Queue::fake();
    $mine = Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'rest_api', 'status' => 'active']);
    $foreign = Tool::factory()->create(['type' => 'rest_api', 'status' => 'active']); // another user
    $chat = Chat::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chat.messages.store', $chat), [
            'content' => 'do it',
            'tool_ids' => [$mine->id, $foreign->id, 'bogus'],
        ])
        ->assertCreated();

    expect($chat->refresh()->tool_ids)->toBe([$mine->id]);
    Queue::assertPushed(RunChatAiJob::class, fn (RunChatAiJob $job) => $job->toolIds === [$mine->id]);
});

it('exposes the user\'s active tools to the chat index', function () {
    $tool = Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'rest_api', 'status' => 'active', 'name' => 'My API']);
    Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'function', 'status' => 'active']); // not exposed

    $this->actingAs($this->user)
        ->get(route('chat.index'))
        ->assertInertia(fn ($page) => $page
            ->has('tools', 1)
            ->where('tools.0.id', $tool->id)
            ->where('tools.0.type', 'rest_api')
        );
});

// ----- MCP server tool wrapper -----

it('converts the MCP schema, names, and calls the server on handle', function () {
    $mock = Mockery::mock(McpClient::class);
    $mock->shouldReceive('callTool')
        ->once()
        ->with(['endpoint' => 'https://mcp.example.invalid/mcp'], null, 'create_order', ['sku' => 'ABC'])
        ->andReturn('Order created.');

    $tool = new McpServerTool(
        [
            'name' => 'create_order',
            'description' => 'Create an order',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['sku' => ['type' => 'string', 'description' => 'SKU']],
                'required' => ['sku'],
            ],
        ],
        ['endpoint' => 'https://mcp.example.invalid/mcp'],
        null,
        $mock,
    );

    expect($tool->name())->toBe('create_order')
        ->and($tool->description())->toBe('Create an order')
        ->and($tool->handle(new ToolRequest(['sku' => 'ABC'])))->toBe('Order created.');
});
