<?php

use App\Models\App;
use App\Models\AppUserRole;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\AppAccessResolver;
use App\Services\Runtime\ProposedActions;
use App\Services\Runtime\RuntimeAgentToolset;
use Illuminate\Support\Collection;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * Runtime agent parity (Phase 3C): the agent acts AS the requesting user and can
 * never exceed their app role. Tool reach is narrowed to the role's readable /
 * writable objects, and the read tools honour the same row_filter + hidden-field
 * restrictions the runtime UI enforces. Execution re-authorization is shared with
 * the UI write path (covered by AppActionPolicyTest).
 */
function rap_policyManifest(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_runtimeagentpolicy',
        'slug' => 'crmp',
        'name' => 'CRM Policed',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_dealobject',
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [
                ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ['id' => 'fld_secretfield', 'slug' => 'secret', 'name' => 'Secret', 'type' => 'string'],
                ['id' => 'fld_ownerfield', 'slug' => 'owner', 'name' => 'Owner', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => false],
                ['id' => 'rol_userrole', 'slug' => 'user', 'name' => 'User', 'is_default' => true],
            ],
            'object_policies' => [
                [
                    'object_id' => 'obj_dealobject', 'role_id' => 'rol_userrole', 'actions' => ['read'],
                    'row_filter' => ['op' => 'eq', 'field_id' => 'fld_ownerfield', 'value_expression' => '{{current_user.id}}'],
                    'field_restrictions' => ['hidden' => ['fld_secretfield']],
                ],
                ['object_id' => 'obj_dealobject', 'role_id' => 'rol_adminrole', 'actions' => ['create', 'read', 'update', 'delete']],
            ],
        ],
        'agent' => ['enabled' => true, 'capabilities' => ['read' => 'all', 'write' => 'all']],
    ];
}

beforeEach(function () {
    // App owned by someone else with no org ⇒ the member is a non-bypass user, so
    // the manifest policies apply through the resolver.
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->builtApp = App::factory()->create(['user_id' => $this->owner->id, 'organization_id' => null]);

    Record::create(['app_id' => $this->builtApp->id, 'object_definition_id' => 'obj_dealobject',
        'organization_id' => null, 'data' => ['name' => 'Mine', 'secret' => 'xyz', 'owner' => (string) $this->member->id]]);
    Record::create(['app_id' => $this->builtApp->id, 'object_definition_id' => 'obj_dealobject',
        'organization_id' => null, 'data' => ['name' => 'Theirs', 'secret' => 'abc', 'owner' => (string) $this->owner->id]]);
});

function rap_userTools(User $member, App $app): Collection
{
    $manifest = rap_policyManifest();
    $access = app(AppAccessResolver::class)->resolve($app, $manifest, $member);
    $context = ['current_user' => ['id' => (string) $member->id, 'email' => $member->email]];

    return collect(app(RuntimeAgentToolset::class)->readTools($app, $manifest, $access, $context))
        ->keyBy(fn ($t) => class_basename($t));
}

it('row-filters and hides fields on the agent read tools for the user role', function () {
    $tools = rap_userTools($this->member, $this->builtApp);

    $rows = json_decode($tools['query_object']->handle(new ToolRequest(['object_id' => 'obj_dealobject'])), true);

    expect($rows['count'])->toBe(1) // only the member's own row
        ->and($rows['rows'][0]['data']['name'])->toBe('Mine')
        ->and($rows['rows'][0]['data'])->not->toHaveKey('secret'); // hidden field stripped
});

it('scopes the agent aggregate to the role row_filter', function () {
    $tools = rap_userTools($this->member, $this->builtApp);

    $count = json_decode($tools['aggregate_object']->handle(new ToolRequest([
        'object_id' => 'obj_dealobject', 'aggregation' => 'count',
    ])), true);

    expect($count['value'])->toBe(1); // not 2 — the other owner's row is filtered out
});

it('offers no write tools to a read-only role', function () {
    $manifest = rap_policyManifest();
    $access = app(AppAccessResolver::class)->resolve($this->builtApp, $manifest, $this->member);

    $writeTools = app(RuntimeAgentToolset::class)->writeTools($manifest, new ProposedActions, $access);

    expect($writeTools)->toBe([]);
});

it('grants the full toolset once the member is assigned the admin role', function () {
    AppUserRole::factory()->create([
        'app_id' => $this->builtApp->id, 'assigned_user_id' => $this->member->id, 'role_slug' => 'admin',
    ]);

    $manifest = rap_policyManifest();
    $access = app(AppAccessResolver::class)->resolve($this->builtApp, $manifest, $this->member);
    $context = ['current_user' => ['id' => (string) $this->member->id, 'email' => $this->member->email]];

    // Admin reads see both rows, including the no-longer-hidden secret.
    $readTools = collect(app(RuntimeAgentToolset::class)->readTools($this->builtApp, $manifest, $access, $context))
        ->keyBy(fn ($t) => class_basename($t));
    $rows = json_decode($readTools['query_object']->handle(new ToolRequest(['object_id' => 'obj_dealobject'])), true);
    expect($rows['count'])->toBe(2)
        ->and($rows['rows'][0]['data'])->toHaveKey('secret');

    // Admin has write grants ⇒ propose_* tools appear.
    $writeTools = app(RuntimeAgentToolset::class)->writeTools($manifest, new ProposedActions, $access);
    expect($writeTools)->not->toBe([]);
});
