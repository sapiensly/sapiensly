<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ValidateManifestTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->appModel), [
        'objects' => [
            ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
                ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
            ]],
        ],
    ]);
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
    $this->ideaObjectId = collect($manifest['objects'])->firstWhere('slug', 'ideas')['id'];
});

it('validate_manifest patch mode reports the exact error of a bad change', function () {
    // An ai.complete step missing its required user_prompt.
    $ops = [[
        'op' => 'add',
        'path' => '/workflows',
        'value' => [[
            'id' => 'wfl_'.strtolower((string) Str::ulid()),
            'slug' => 'cmo_idea',
            'name' => 'CMO Idea',
            'trigger' => ['type' => 'manual'],
            'steps' => [[
                'id' => 'stp_'.strtolower((string) Str::ulid()),
                'type' => 'ai.complete',
                'output_variable' => 'idea',
            ]],
        ]],
    ]];

    // The step's `type` matched, so the error names the exact missing prop
    // rather than a generic per-type catalog hint.
    SapiensServer::actingAs($this->user)
        ->tool(ValidateManifestTool::class, ['app_slug' => 'content_engine', 'ops' => $ops])
        ->assertOk()
        ->assertSee('user_prompt');
});

it('validate_manifest patch mode passes a valid CMO-idea workflow', function () {
    $ops = [[
        'op' => 'add',
        'path' => '/workflows',
        'value' => [[
            'id' => 'wfl_'.strtolower((string) Str::ulid()),
            'slug' => 'cmo_idea',
            'name' => 'CMO Idea',
            'trigger' => ['type' => 'manual', 'label' => 'CMO Idea'],
            'steps' => [
                ['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'ai.complete', 'user_prompt' => 'Give a content idea', 'output_variable' => 'idea'],
                ['id' => 'stp_'.strtolower((string) Str::ulid()), 'type' => 'record.create', 'object_id' => $this->ideaObjectId, 'values' => ['title' => '{{vars.idea}}']],
            ],
        ]],
    ]];

    // A clean pass: no step hint, no errors surfaced.
    SapiensServer::actingAs($this->user)
        ->tool(ValidateManifestTool::class, ['app_slug' => 'content_engine', 'ops' => $ops])
        ->assertOk()
        ->assertDontSee('list_available_steps')
        ->assertDontSee('patch_failed');
});

it('validate_manifest patch mode flags a malformed patch as patch_failed', function () {
    // Targets a path that does not exist.
    $ops = [['op' => 'replace', 'path' => '/objects/99/name', 'value' => 'X']];

    SapiensServer::actingAs($this->user)
        ->tool(ValidateManifestTool::class, ['app_slug' => 'content_engine', 'ops' => $ops])
        ->assertOk()
        ->assertSee('patch_failed');
});
