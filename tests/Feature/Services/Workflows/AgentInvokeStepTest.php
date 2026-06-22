<?php

use App\Models\Agent;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\LLMService;
use App\Services\Workflows\WorkflowEngine;
use Illuminate\Support\Str;

function ai_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function ai_manifest(string $appId, array $workflow, string $objectId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'ai_test',
        'name' => 'AI Test',
        'version' => 1,
        'objects' => [[
            'id' => $objectId,
            'slug' => 'ideas',
            'name' => 'Ideas',
            'fields' => [['id' => ai_id('fld'), 'slug' => 'title', 'name' => 'Title', 'type' => 'string']],
        ]],
        'pages' => [],
        'workflows' => [$workflow],
        'permissions' => ['roles' => [['id' => ai_id('rol'), 'slug' => 'admin', 'name' => 'A']]],
    ];
}

beforeEach(function () {
    $this->engine = app(WorkflowEngine::class);
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
    ]);
    $this->agent = Agent::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'model' => 'claude-sonnet-4-20250514',
        'prompt_template' => 'You are the CMO.',
    ]);
    $this->objectId = ai_id('obj');
});

function ai_workflow(string $agentId, string $objectId): array
{
    return [
        'id' => ai_id('wkf'), 'slug' => 'cmo_idea', 'name' => 'CMO Idea',
        'trigger' => ['type' => 'manual', 'label' => 'CMO Idea'],
        'steps' => [
            ['id' => ai_id('stp'), 'type' => 'agent.invoke', 'agent_id' => $agentId, 'message' => 'Generate a content idea', 'output_variable' => 'idea'],
            ['id' => ai_id('stp'), 'type' => 'record.create', 'object_id' => $objectId, 'values' => ['title' => '{{vars.idea.text}}']],
        ],
    ];
}

it('agent.invoke runs the configured agent and feeds its reply into record.create', function () {
    $this->mock(LLMService::class, function ($m) {
        $m->shouldReceive('setContext')->andReturnSelf();
        $m->shouldReceive('chatWithKnowledgeAndTools')
            ->once()
            ->andReturn(['response' => (object) ['text' => 'AI in customer support: 5 wins'], 'knowledge_bases' => [], 'chunk_count' => 0]);
    });

    $workflow = ai_workflow($this->agent->id, $this->objectId);
    $manifest = ai_manifest($this->testApp->id, $workflow, $this->objectId);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('completed');

    $record = Record::query()->where('app_id', $this->testApp->id)->where('object_definition_id', $this->objectId)->first();
    expect($record)->not->toBeNull();
    expect($record->data['title'])->toBe('AI in customer support: 5 wins');
});

it('agent.invoke is simulated during a dry run and never calls the agent', function () {
    $this->mock(LLMService::class, function ($m) {
        $m->shouldNotReceive('chatWithKnowledgeAndTools');
    });

    $workflow = ai_workflow($this->agent->id, $this->objectId);
    $manifest = ai_manifest($this->testApp->id, $workflow, $this->objectId);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user, dryRun: true);

    expect($run->status)->toBe('completed');
    expect(Record::query()->where('app_id', $this->testApp->id)->count())->toBe(0);
});

it('agent.invoke fails legibly on an unknown agent', function () {
    $workflow = ai_workflow(ai_id('agt'), $this->objectId);
    $manifest = ai_manifest($this->testApp->id, $workflow, $this->objectId);

    $run = $this->engine->run($this->testApp, $manifest, $workflow, 'manual', [], $this->user);

    expect($run->status)->toBe('failed');
    expect($run->error)->toContain('unknown agent');
});
