<?php

use App\DTOs\ToolExecutionResult;
use App\Enums\AgentStatus;
use App\Enums\ConnectorEffect;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Integrations\UseToolTool;
use App\Models\Tool;
use App\Models\User;
use App\Services\ToolExecutionService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('use_tool executes a read-effect tool and returns its result', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'status' => AgentStatus::Active,
        'config' => ['base_url' => 'https://api.example.com', 'method' => 'GET', 'auth_type' => 'none'],
    ]);

    $this->mock(ToolExecutionService::class)
        ->shouldReceive('execute')
        ->once()
        ->andReturn(ToolExecutionResult::success(['ok' => true]));

    SapiensServer::actingAs($this->user)
        ->tool(UseToolTool::class, ['tool_id' => $tool->id])
        ->assertOk()
        ->assertSee('read')
        ->assertSee('ok');
});

it('use_tool refuses a non-safe write and never executes it', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'status' => AgentStatus::Active,
        'safe' => false,
        'config' => ['base_url' => 'https://api.example.com', 'method' => 'POST', 'auth_type' => 'none'],
    ]);

    // Execution must NOT happen for a gated write.
    $this->mock(ToolExecutionService::class)->shouldNotReceive('execute');

    SapiensServer::actingAs($this->user)
        ->tool(UseToolTool::class, ['tool_id' => $tool->id, 'parameters' => ['x' => 1]])
        ->assertOk()
        ->assertSee('refused')
        ->assertSee('unconfirmed_write')
        ->assertSee('blast_radius');
});

it('use_tool runs a write that the author marked safe', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'status' => AgentStatus::Active,
        'safe' => true,
        'config' => ['base_url' => 'https://api.example.com', 'method' => 'POST', 'auth_type' => 'none'],
    ]);

    $this->mock(ToolExecutionService::class)
        ->shouldReceive('execute')
        ->once()
        ->andReturn(ToolExecutionResult::success(['created' => 1]));

    SapiensServer::actingAs($this->user)
        ->tool(UseToolTool::class, ['tool_id' => $tool->id])
        ->assertOk()
        ->assertSee('created');
});

it('use_tool honours an author-pinned write effect even for a GET', function () {
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => 'rest_api',
        'status' => AgentStatus::Active,
        'effect' => ConnectorEffect::Write,
        'safe' => false,
        'config' => ['base_url' => 'https://api.example.com', 'method' => 'GET', 'auth_type' => 'none'],
    ]);

    $this->mock(ToolExecutionService::class)->shouldNotReceive('execute');

    SapiensServer::actingAs($this->user)
        ->tool(UseToolTool::class, ['tool_id' => $tool->id])
        ->assertOk()
        ->assertSee('unconfirmed_write');
});

it('use_tool refuses an mcp tool with guidance instead of executing', function () {
    $tool = Tool::factory()->mcp()->create([
        'user_id' => $this->user->id,
        'status' => AgentStatus::Active,
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(UseToolTool::class, ['tool_id' => $tool->id])
        ->assertOk()
        ->assertSee('refused')
        ->assertSee('unsupported_type');
});

it('use_tool will not run a tool outside the caller context', function () {
    $other = Tool::factory()->create(['type' => 'rest_api', 'status' => AgentStatus::Active]);

    SapiensServer::actingAs($this->user)
        ->tool(UseToolTool::class, ['tool_id' => $other->id])
        ->assertHasErrors();
});
