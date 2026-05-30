<?php

use App\Ai\Tools\Builder\DeleteBlockByIdTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

function dbid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function manifestWithBlocks(string $appId, array $pageBlocks): array
{
    $fldId = dbid('fld');

    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_crm',
        'name' => 'Mini CRM',
        'version' => 1,
        'objects' => [[
            'id' => dbid('obj'),
            'slug' => 'clientes',
            'name' => 'Cliente',
            'fields' => [
                ['id' => $fldId, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => dbid('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/',
            'blocks' => $pageBlocks,
        ]],
        'permissions' => [
            'roles' => [['id' => dbid('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    $this->manifestService = app(AppManifestService::class);
    $this->validator = app(ManifestValidator::class);
});

function makeTools(AppManifestService $mfs, ManifestValidator $val, App $app): array
{
    $propose = new ProposeChangeTool($app->fresh(), $mfs, $val);
    $delete = new DeleteBlockByIdTool($app->fresh(), $mfs, $propose);

    return [$delete, $propose];
}

it('removes a top-level block by id and records the proposal', function () {
    $heading = ['id' => dbid('blk'), 'type' => 'heading', 'content' => 'Hi', 'level' => 1];
    $divider = ['id' => dbid('blk'), 'type' => 'divider'];
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithBlocks($this->testApp->id, [$heading, $divider]),
        $this->user,
    );

    [$delete, $propose] = makeTools($this->manifestService, $this->validator, $this->testApp);

    $result = json_decode($delete->handle(new ToolRequest([
        'block_id' => $divider['id'],
        'change_summary' => 'Remove divider',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['removed_block_id'])->toBe($divider['id'])
        ->and($result['removed_path'])->toBe('/pages/0/blocks/1');

    $proposal = $propose->lastProposal();
    expect($proposal)->not->toBeNull()
        ->and($proposal['patch'])->toBe([['op' => 'remove', 'path' => '/pages/0/blocks/1']])
        ->and($proposal['draft_manifest']['pages'][0]['blocks'])->toHaveCount(1)
        ->and($proposal['draft_manifest']['pages'][0]['blocks'][0]['id'])->toBe($heading['id']);
});

it('finds a block nested inside a tabs/accordion/split_view tree', function () {
    $deepBlock = ['id' => dbid('blk'), 'type' => 'divider'];
    $tabsBlock = [
        'id' => dbid('blk'),
        'type' => 'tabs',
        'tabs' => [[
            'id' => dbid('blk'), 'label' => 'A',
            'blocks' => [[
                'id' => dbid('blk'),
                'type' => 'accordion',
                'sections' => [[
                    'id' => dbid('blk'), 'title' => 'S',
                    'blocks' => [$deepBlock],
                ]],
            ]],
        ]],
    ];
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithBlocks($this->testApp->id, [$tabsBlock]),
        $this->user,
    );

    [$delete, $propose] = makeTools($this->manifestService, $this->validator, $this->testApp);

    $result = json_decode($delete->handle(new ToolRequest([
        'block_id' => $deepBlock['id'],
        'change_summary' => 'Remove nested divider',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['removed_path'])->toBe('/pages/0/blocks/0/tabs/0/blocks/0/sections/0/blocks/0');
});

it('returns an error (and records no proposal) when the block_id is unknown', function () {
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithBlocks($this->testApp->id, [['id' => dbid('blk'), 'type' => 'divider']]),
        $this->user,
    );

    [$delete, $propose] = makeTools($this->manifestService, $this->validator, $this->testApp);

    $result = json_decode($delete->handle(new ToolRequest([
        'block_id' => 'blk_doesnotexist_'.strtolower((string) Str::ulid()),
        'change_summary' => 'try',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('unresolved_ref')
        ->and($propose->lastProposal())->toBeNull();
});

it('refuses requests with an empty block_id', function () {
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithBlocks($this->testApp->id, [['id' => dbid('blk'), 'type' => 'divider']]),
        $this->user,
    );

    [$delete] = makeTools($this->manifestService, $this->validator, $this->testApp);

    $result = json_decode($delete->handle(new ToolRequest([
        'block_id' => '',
        'change_summary' => 'noop',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code'))->toContain('bad_input');
});

it('finds a block that was added to the running draft in the same turn', function () {
    // Tests the fix: before the proposeTool was consulted for the manifest
    // read, calling delete_block_by_id on a block that was only added in
    // the current turn would fail because the active manifest didn't have
    // it yet. With currentManifest() the tool sees the draft.
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithBlocks($this->testApp->id, []),
        $this->user,
    );
    [$delete, $propose] = makeTools($this->manifestService, $this->validator, $this->testApp);

    // Add a block via propose_change first — it lives in the draft only.
    $draftBlockId = dbid('blk');
    $propose->recordProposal(
        [['op' => 'add', 'path' => '/pages/0/blocks/-', 'value' => [
            'id' => $draftBlockId, 'type' => 'heading', 'content' => 'New', 'level' => 1,
        ]]],
        'add heading',
    );

    // Now try to delete it — must succeed by reading the draft.
    $result = json_decode($delete->handle(new ToolRequest([
        'block_id' => $draftBlockId,
        'change_summary' => 'remove that heading',
    ])), true);

    expect($result['ok'])->toBeTrue();
    // Final draft has the block gone.
    $final = $propose->runningDraft();
    expect($final['pages'][0]['blocks'])->toBe([]);
});
