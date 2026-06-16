<?php

use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;

function insertId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function manifestWithPageBlocks(string $appId, array $pageBlocks): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_crm',
        'name' => 'Mini CRM',
        'version' => 1,
        'objects' => [[
            'id' => insertId('obj'),
            'slug' => 'leads',
            'name' => 'Lead',
            'fields' => [
                ['id' => insertId('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => insertId('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/',
            'blocks' => $pageBlocks,
        ]],
        'permissions' => [
            'roles' => [['id' => insertId('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    $this->manifestService = app(AppManifestService::class);
    $this->validator = app(ManifestValidator::class);
});

function insertPropose(AppManifestService $mfs, ManifestValidator $val, App $app): ProposeChangeTool
{
    return new ProposeChangeTool($app->fresh(), $mfs, $val);
}

it('inserts a block at a numeric index through propose_change without corrupting the draft', function () {
    // Reproduces the conversation's failure: adding a block at /pages/0/blocks/1
    // used to fail validation with "data (string) must match type: object"
    // because the underlying splice spread the object into scalars.
    $table = ['id' => insertId('blk'), 'type' => 'heading', 'content' => 'Leads', 'level' => 1];
    $footer = ['id' => insertId('blk'), 'type' => 'divider'];
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithPageBlocks($this->testApp->id, [$table, $footer]),
        $this->user,
    );

    $propose = insertPropose($this->manifestService, $this->validator, $this->testApp);

    $inserted = ['id' => insertId('blk'), 'type' => 'heading', 'content' => 'Nuevo Lead', 'level' => 2];
    $result = $propose->recordProposal(
        [['op' => 'add', 'path' => '/pages/0/blocks/1', 'value' => $inserted]],
        'Insert a heading between the table and the footer',
    );

    expect($result['ok'])->toBeTrue();

    $blocks = $propose->runningDraft()['pages'][0]['blocks'];
    expect(array_column($blocks, 'id'))->toBe([$table['id'], $inserted['id'], $footer['id']])
        ->and($blocks[1])->toBe($inserted);
});

it('applies two sequential index inserts in a single propose_change call', function () {
    // The conversation tried adding a modal at /blocks/1 and a button at /blocks/2
    // in one patch; the spread bug made the second op land on a corrupted array.
    $table = ['id' => insertId('blk'), 'type' => 'heading', 'content' => 'Leads', 'level' => 1];
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithPageBlocks($this->testApp->id, [$table]),
        $this->user,
    );

    $propose = insertPropose($this->manifestService, $this->validator, $this->testApp);

    $first = ['id' => insertId('blk'), 'type' => 'divider'];
    $second = ['id' => insertId('blk'), 'type' => 'heading', 'content' => 'Nuevo Lead', 'level' => 2];
    $result = $propose->recordProposal(
        [
            ['op' => 'add', 'path' => '/pages/0/blocks/0', 'value' => $first],
            ['op' => 'add', 'path' => '/pages/0/blocks/1', 'value' => $second],
        ],
        'Insert two blocks at the front',
    );

    expect($result['ok'])->toBeTrue()
        ->and(array_column($propose->runningDraft()['pages'][0]['blocks'], 'id'))
        ->toBe([$first['id'], $second['id'], $table['id']]);
});

it('reorders blocks with a move op through propose_change', function () {
    $a = ['id' => insertId('blk'), 'type' => 'heading', 'content' => 'A', 'level' => 1];
    $b = ['id' => insertId('blk'), 'type' => 'divider'];
    $c = ['id' => insertId('blk'), 'type' => 'heading', 'content' => 'C', 'level' => 2];
    $this->manifestService->createVersion(
        $this->testApp,
        manifestWithPageBlocks($this->testApp->id, [$a, $b, $c]),
        $this->user,
    );

    $propose = insertPropose($this->manifestService, $this->validator, $this->testApp);

    $result = $propose->recordProposal(
        [['op' => 'move', 'from' => '/pages/0/blocks/2', 'path' => '/pages/0/blocks/0']],
        'Move the last block to the front',
    );

    expect($result['ok'])->toBeTrue()
        ->and(array_column($propose->runningDraft()['pages'][0]['blocks'], 'id'))
        ->toBe([$c['id'], $a['id'], $b['id']]);
});
