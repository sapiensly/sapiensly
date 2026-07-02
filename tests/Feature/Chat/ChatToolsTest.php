<?php

use App\Ai\Tools\McpServerTool;
use App\Events\Chat\ChatToolCall;
use App\Jobs\RunChatAiJob;
use App\Models\AiProvider;
use App\Models\Chat;
use App\Models\Tool;
use App\Models\User;
use App\Services\Chat\ChatAiService;
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

it('carries the tool-call lifecycle (phase, id, status) in its broadcast payload', function () {
    // start: a running chip, no verdict yet.
    expect((new ChatToolCall('c1', 'm1', 'check_orders', 'start', 'tc_1'))->broadcastWith())->toBe([
        'message_id' => 'm1',
        'tool_name' => 'check_orders',
        'phase' => 'start',
        'tool_id' => 'tc_1',
        'successful' => null,
    ]);

    // result: correlated by tool_id, flips the chip to done/failed.
    $result = (new ChatToolCall('c1', 'm1', 'check_orders', 'result', 'tc_1', false))->broadcastWith();
    expect($result['phase'])->toBe('result')
        ->and($result['tool_id'])->toBe('tc_1')
        ->and($result['successful'])->toBeFalse();
});

// ----- Zero-config tool activation -----

it('auto-activates every active connector the user can see when the composer made no selection', function () {
    $rest = Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'rest_api', 'status' => 'active']);
    $mcp = Tool::factory()->mcp()->create(['user_id' => $this->user->id, 'status' => 'active']);
    Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'rest_api', 'status' => 'draft']); // inactive
    Tool::factory()->function()->create(['user_id' => $this->user->id, 'status' => 'active']); // not a chat connector
    Tool::factory()->create(['type' => 'rest_api', 'status' => 'active']); // another user's

    $auto = app(ChatAiService::class)->autoAttachableToolIds($this->user);

    expect($auto)->toContain($rest->id, $mcp->id)
        ->and($auto)->toHaveCount(2);
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
