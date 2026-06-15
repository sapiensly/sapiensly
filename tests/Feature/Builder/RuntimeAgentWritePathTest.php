<?php

use App\Ai\Tools\Runtime\Agent\ProposeCreateRecordTool;
use App\Ai\Tools\Runtime\Agent\ProposeDeleteRecordTool;
use App\Models\App;
use App\Models\Integration;
use App\Models\Record;
use App\Models\RuntimeAgentConversation;
use App\Models\RuntimeAgentMessage;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Runtime\ProposedActions;
use App\Services\Runtime\RuntimeAgentToolset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * Builder power #3, write slice (2a) — the propose-don't-mutate gate (Rule 2):
 * the agent's write tools record a proposal and change NOTHING; approval runs it
 * through the same write path the runtime UI uses; dismiss discards it. See
 * docs/app-builder-runtime-agent-contract.md §8.
 */
function rw_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * @return array<string, mixed>
 */
function rw_object(string $id, array $extra = []): array
{
    return [
        'id' => $id,
        'slug' => 'deals',
        'name' => 'Deal',
        'fields' => [
            ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
            ['id' => 'fld_amountfield', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'number'],
        ],
        ...$extra,
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->writeApp = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'write_app', 'visibility' => 'private']);
});

it('exposes the gated write tools only when write is granted', function () {
    $manifest = [
        'objects' => [rw_object('obj_dealobject')],
        'agent' => ['enabled' => true, 'capabilities' => ['read' => 'all', 'write' => ['obj_dealobject']]],
    ];

    $tools = app(RuntimeAgentToolset::class)->writeTools($manifest, new ProposedActions);
    $names = array_map(fn ($t) => class_basename($t), $tools);
    expect($names)->toEqualCanonicalizing(['propose_create_record', 'propose_update_record', 'propose_delete_record']);

    // No write grant ⇒ no write tools (read-only agent).
    $readOnly = ['objects' => [rw_object('obj_dealobject')], 'agent' => ['enabled' => true, 'capabilities' => ['read' => 'all', 'write' => []]]];
    expect(app(RuntimeAgentToolset::class)->writeTools($readOnly, new ProposedActions))->toBe([]);
});

it('records a proposal and mutates NOTHING (Rule 2)', function () {
    $proposals = new ProposedActions;
    $manifest = ['objects' => [rw_object('obj_dealobject')]];

    $tool = new ProposeCreateRecordTool($manifest, ['obj_dealobject'], $proposals);
    $out = json_decode($tool->handle(new ToolRequest([
        'object_id' => 'obj_dealobject',
        'values' => ['name' => 'Acme', 'amount' => 1000],
    ])), true);

    expect($out['ok'])->toBeTrue()
        ->and($out['preview'])->toContain('Create Deal')
        ->and($proposals->all())->toHaveCount(1)
        ->and($proposals->all()[0]['action'])->toMatchArray(['type' => 'create_record', 'object_id' => 'obj_dealobject']);

    // The load-bearing assertion: proposing wrote nothing.
    expect(Record::count())->toBe(0);
});

it('refuses to propose deleting a connected record', function () {
    $manifest = ['objects' => [rw_object('obj_dealobject', ['source' => ['type' => 'connected', 'integration_id' => 'integ_x']])]];
    $tool = new ProposeDeleteRecordTool($manifest, ['obj_dealobject'], new ProposedActions);

    $out = json_decode($tool->handle(new ToolRequest(['object_id' => 'obj_dealobject', 'record_id' => 'd1'])), true);
    expect($out['ok'])->toBeFalse()->and($out['error'])->toContain('not supported');
});

it('rejects proposing against an ungranted object', function () {
    $tool = new ProposeCreateRecordTool(['objects' => [rw_object('obj_dealobject')]], ['obj_other'], new ProposedActions);
    $out = json_decode($tool->handle(new ToolRequest(['object_id' => 'obj_dealobject', 'values' => ['name' => 'x']])), true);
    expect($out['ok'])->toBeFalse()->and($out['error'])->toContain('not available');
});

/**
 * Publish an app manifest with an agent + the given object, and open a
 * conversation with a pending proposal message carrying $actions.
 *
 * @param  list<array<string, mixed>>  $objects
 * @param  list<array<string, mixed>>  $actions
 */
function rw_pendingProposal(App $app, User $user, array $objects, array $actions): RuntimeAgentMessage
{
    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Write App',
        'version' => 1,
        'objects' => $objects,
        'pages' => [],
        'permissions' => ['roles' => [['id' => rw_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
        'agent' => ['enabled' => true, 'capabilities' => ['read' => 'all', 'write' => 'all']],
    ], $user);

    $conversation = RuntimeAgentConversation::create([
        'app_id' => $app->id,
        'user_id' => $user->id,
        'status' => 'active',
    ]);

    return RuntimeAgentMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'I prepared a change for your approval.',
        'message_type' => 'action_proposal',
        'action_payload' => ['status' => 'pending', 'actions' => $actions, 'previews' => ['…']],
        'status' => 'none',
    ]);
}

it('executes an internal write only on approval, through the write path', function () {
    $message = rw_pendingProposal($this->writeApp, $this->user, [rw_object('obj_dealobject')], [
        ['type' => 'create_record', 'object_id' => 'obj_dealobject', 'values' => ['name' => 'Acme', 'amount' => 1000]],
    ]);

    // Nothing exists before approval.
    expect(Record::query()->where('app_id', $this->writeApp->id)->count())->toBe(0);

    $this->actingAs($this->user)
        ->postJson("/r/write_app/agent/messages/{$message->id}/approve")
        ->assertOk()
        ->assertJsonPath('message.action_payload.status', 'executed');

    expect(Record::query()->where('app_id', $this->writeApp->id)->count())->toBe(1);
    $record = Record::query()->where('app_id', $this->writeApp->id)->first();
    expect($record->data['name'])->toBe('Acme');
});

it('executes a connected write on approval, reaching the external system', function () {
    Http::fake(['api.example.com/*' => Http::response(['id' => 'd99'], 201)]);
    $integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
    ]);

    $connected = rw_object('obj_dealobject', ['source' => [
        'type' => 'connected',
        'integration_id' => $integration->id,
        'id_path' => 'id',
        'operations' => [
            'list' => ['method' => 'GET', 'path' => '/deals'],
            'create' => ['method' => 'POST', 'path' => '/deals'],
        ],
        'field_map' => [['field_id' => 'fld_namefield', 'external_path' => 'properties.dealname']],
    ]]);

    $message = rw_pendingProposal($this->writeApp, $this->user, [$connected], [
        ['type' => 'create_record', 'object_id' => 'obj_dealobject', 'values' => ['name' => 'Acme']],
    ]);

    $this->actingAs($this->user)
        ->postJson("/r/write_app/agent/messages/{$message->id}/approve")
        ->assertOk()
        ->assertJsonPath('message.action_payload.status', 'executed');

    expect(Record::count())->toBe(0); // passthrough — nothing stored locally
    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && str_contains($req->url(), 'api.example.com/deals')
        && $req->data()['properties']['dealname'] === 'Acme');
});

it('dismisses a proposal without executing it', function () {
    $message = rw_pendingProposal($this->writeApp, $this->user, [rw_object('obj_dealobject')], [
        ['type' => 'create_record', 'object_id' => 'obj_dealobject', 'values' => ['name' => 'Acme']],
    ]);

    $this->actingAs($this->user)
        ->postJson("/r/write_app/agent/messages/{$message->id}/dismiss")
        ->assertOk()
        ->assertJsonPath('message.action_payload.status', 'dismissed');

    expect(Record::query()->where('app_id', $this->writeApp->id)->count())->toBe(0);
});

it('cannot approve the same proposal twice', function () {
    $message = rw_pendingProposal($this->writeApp, $this->user, [rw_object('obj_dealobject')], [
        ['type' => 'create_record', 'object_id' => 'obj_dealobject', 'values' => ['name' => 'Acme']],
    ]);

    $this->actingAs($this->user)->postJson("/r/write_app/agent/messages/{$message->id}/approve")->assertOk();
    // Second approval is a 404 (no longer pending) — single-shot.
    $this->actingAs($this->user)->postJson("/r/write_app/agent/messages/{$message->id}/approve")->assertNotFound();

    expect(Record::query()->where('app_id', $this->writeApp->id)->count())->toBe(1);
});
