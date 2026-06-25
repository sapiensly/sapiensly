<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\CreateAppTool;
use App\Mcp\Tools\Build\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('propose_change tells the model exactly what failed, not just a count', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($app), [
        'objects' => [['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]]],
    ]);
    $manifests->createVersion($app, $manifest, $this->user, 'seed');

    // A workflow whose ai.complete step is missing its required user_prompt.
    $ops = [['op' => 'add', 'path' => '/workflows', 'value' => [[
        'id' => 'wfl_'.strtolower((string) Str::ulid()),
        'slug' => 'cmo_idea',
        'name' => 'CMO Idea',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'ai.complete', 'output_variable' => 'idea']],
    ]]]];

    // A rejected change comes back as the SAME structured result validate_manifest
    // returns — {applied:false, valid:false, errors:[{path, message, code, ...}]} —
    // not a flattened, truncated string. The validator's authoring hint survives.
    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, [
            'app_slug' => 'content_engine',
            'ops' => $ops,
            'change_summary' => 'Add CMO Idea workflow',
        ])
        ->assertOk()
        ->assertSee('"applied"')
        ->assertSee('"valid"')
        ->assertSee('"code"')
        ->assertSee('list_available_steps');
});

it('create_app creates an app seeded with a valid version 1', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateAppTool::class, [
            'name' => 'Support Desk',
            'slug' => 'support_desk',
            'description' => 'Ticketing',
        ])
        ->assertOk()
        ->assertSee('support_desk')
        ->assertSee('version_number');

    $app = App::where('user_id', $this->user->id)->where('slug', 'support_desk')->first();
    expect($app)->not->toBeNull();
    expect($app->current_version_id)->not->toBeNull();
    expect($app->versions()->count())->toBe(1);
});

it('create_app rejects a duplicate slug in the same account', function () {
    App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'taken',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(CreateAppTool::class, ['name' => 'Dup', 'slug' => 'taken'])
        ->assertHasErrors();
});

it('create_app rejects an invalid slug', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateAppTool::class, ['name' => 'Bad', 'slug' => 'Not Valid!'])
        ->assertHasErrors();

    expect(App::where('name', 'Bad')->exists())->toBeFalse();
});

it('propose_change returns the resolved paths a patch landed at', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'tracker',
    ]);
    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($app), [
        'objects' => [['name' => 'Tasks', 'slug' => 'tasks', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]]],
    ]);
    $manifests->createVersion($app, $manifest, $this->user, 'seed');

    // Append a workflow with '/workflows/-' — the response must resolve it to the
    // concrete index (the first workflow → /workflows/0) so a follow-up patch can
    // target it without re-reading the manifest.
    $ops = [['op' => 'add', 'path' => '/workflows/-', 'value' => [
        'id' => 'wfl_'.strtolower((string) Str::ulid()),
        'slug' => 'nightly',
        'name' => 'Nightly',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'log', 'message' => 'hi']],
    ]]];

    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, [
            'app_slug' => 'tracker',
            'ops' => $ops,
            'change_summary' => 'Add nightly workflow',
        ])
        ->assertOk()
        ->assertSee('"applied"')
        ->assertSee('changed_paths')
        ->assertSee('/workflows/0')
        ->assertSee('/workflows/-');
});
