<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Support\Str;

/**
 * The end of the `unresolved_ref` class of failure, through the tool an agent
 * actually calls: a patch written entirely in slugs, applied and stored with
 * real ids.
 */
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
});

it('accepts a page whose every reference is a slug', function () {
    // Not one id in the patch. Before the resolver this was the shape that
    // produced eighteen rejections across two builds, because the ids it had to
    // carry instead all begin with the same ten characters.
    $ops = [[
        'op' => 'add',
        'path' => '/pages/-',
        'value' => [
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'ideas_board',
            'name' => 'Ideas board',
            'path' => '/ideas_board',
            'blocks' => [[
                'id' => 'blk_'.strtolower((string) Str::ulid()),
                'type' => 'table',
                'data_source' => ['object_id' => 'ideas'],
                'columns' => [[
                    'id' => 'col_'.strtolower((string) Str::ulid()),
                    'field_id' => 'title',
                ]],
            ]],
        ],
    ]];

    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, ['app_slug' => 'content_engine', 'ops' => $ops])
        ->assertOk()
        ->assertSee('applied');

    $this->appModel->refresh();
    $manifest = app(AppManifestService::class)->getActiveManifest($this->appModel);

    $page = collect($manifest['pages'])->firstWhere('slug', 'ideas_board');
    $object = collect($manifest['objects'])->firstWhere('slug', 'ideas');
    $titleId = collect($object['fields'])->firstWhere('slug', 'title')['id'];

    // Stored as ids: the manifest keeps its own contract, the model just no
    // longer has to reproduce it.
    expect($page)->not->toBeNull()
        ->and($page['blocks'][0]['data_source']['object_id'])->toBe($object['id'])
        ->and($page['blocks'][0]['columns'][0]['field_id'])->toBe($titleId);
});

it('still rejects a name that matches nothing', function () {
    // The resolver must never rescue a reference by guessing — a silent
    // mis-wiring is worse than the rejection it replaces.
    $ops = [[
        'op' => 'add',
        'path' => '/pages/-',
        'value' => [
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'broken',
            'name' => 'Broken',
            'path' => '/broken',
            'blocks' => [[
                'id' => 'blk_'.strtolower((string) Str::ulid()),
                'type' => 'table',
                'data_source' => ['object_id' => 'ideas'],
                'columns' => [[
                    'id' => 'col_'.strtolower((string) Str::ulid()),
                    'field_id' => 'no_such_field',
                ]],
            ]],
        ],
    ]];

    SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, ['app_slug' => 'content_engine', 'ops' => $ops])
        ->assertOk()
        ->assertSee('unresolved_ref');
});
