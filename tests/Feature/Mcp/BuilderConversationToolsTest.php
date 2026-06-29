<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ContinueBuilderConversationTool;
use App\Mcp\Tools\Build\GetBuilderConversationTool;
use App\Mcp\Tools\Build\ListBuilderConversationsTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

function bconvManifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => 'Mini',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
    app(AppManifestService::class)->createVersion($this->testApp, bconvManifest($this->testApp->id), $this->user);
});

it('list_builder_conversations returns an app\'s builder sessions', function () {
    $older = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'archived',
    ]);
    BuilderMessage::create(['conversation_id' => $older->id, 'role' => 'user', 'content' => 'primer intento', 'status' => 'none']);

    $newer = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    BuilderMessage::create(['conversation_id' => $newer->id, 'role' => 'user', 'content' => 'segundo intento', 'status' => 'none']);

    SapiensServer::actingAs($this->user)
        ->tool(ListBuilderConversationsTool::class, ['app_slug' => $this->testApp->slug])
        ->assertOk()
        ->assertSee($older->id)
        ->assertSee($newer->id);
});

it('list_builder_conversations filters by status', function () {
    $active = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    $archived = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'archived',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(ListBuilderConversationsTool::class, ['app_slug' => $this->testApp->slug, 'status' => 'active'])
        ->assertOk()
        ->assertSee($active->id)
        ->assertDontSee($archived->id);
});

it('list_builder_conversations rejects an app the caller cannot see', function () {
    $other = User::factory()->create();
    $otherApp = App::factory()->create(['user_id' => $other->id, 'visibility' => 'private']);

    SapiensServer::actingAs($this->user)
        ->tool(ListBuilderConversationsTool::class, ['app_slug' => $otherApp->slug])
        ->assertHasErrors();
});

it('get_builder_conversation returns the full transcript by id', function () {
    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    BuilderMessage::create(['conversation_id' => $conv->id, 'role' => 'user', 'content' => 'Quiero un POS', 'status' => 'none']);
    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'Listo',
        'change_summary' => 'Creé Comandas', 'status' => 'applied',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(GetBuilderConversationTool::class, ['conversation_id' => $conv->id])
        ->assertOk()
        ->assertSee('Quiero un POS')
        ->assertSee('Creé Comandas');
});

it('get_builder_conversation resolves the most recent conversation by app_slug', function () {
    $old = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'archived',
    ]);
    BuilderMessage::create(['conversation_id' => $old->id, 'role' => 'user', 'content' => 'vieja sesion', 'status' => 'none']);

    $current = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    BuilderMessage::create(['conversation_id' => $current->id, 'role' => 'user', 'content' => 'sesion vigente', 'status' => 'none']);

    SapiensServer::actingAs($this->user)
        ->tool(GetBuilderConversationTool::class, ['app_slug' => $this->testApp->slug])
        ->assertOk()
        ->assertSee('sesion vigente');
});

it('get_builder_conversation withholds the full patch unless include_patches is set', function () {
    $conv = BuilderConversation::create([
        'app_id' => $this->testApp->id, 'user_id' => $this->user->id, 'status' => 'active',
    ]);
    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'ok',
        'change_summary' => 'Agregué una página',
        'proposed_patch' => [['op' => 'add', 'path' => '/pages/-', 'value' => ['x' => 1]]],
        'status' => 'applied',
    ]);

    // Default: the op count is reported but the raw ops are not.
    SapiensServer::actingAs($this->user)
        ->tool(GetBuilderConversationTool::class, ['conversation_id' => $conv->id])
        ->assertOk()
        ->assertSee('proposed_patch_op_count')
        ->assertDontSee('"op":"add"');

    // include_patches → the full RFC 6902 ops are shipped.
    SapiensServer::actingAs($this->user)
        ->tool(GetBuilderConversationTool::class, ['conversation_id' => $conv->id, 'include_patches' => true])
        ->assertOk()
        ->assertSee('"op":"add"');
});

it('get_builder_conversation rejects an unknown id', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetBuilderConversationTool::class, ['conversation_id' => 'cnv_nope'])
        ->assertHasErrors();
});

it('get_builder_conversation requires conversation_id or app_slug', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetBuilderConversationTool::class, [])
        ->assertHasErrors();
});

it('continue_builder_conversation rejects an unknown conversation id', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ContinueBuilderConversationTool::class, ['conversation_id' => 'cnv_nope', 'message' => 'sigue'])
        ->assertHasErrors();
});

it('continue_builder_conversation requires conversation_id or app_slug', function () {
    SapiensServer::actingAs($this->user)
        ->tool(ContinueBuilderConversationTool::class, ['message' => 'sigue'])
        ->assertHasErrors();
});
