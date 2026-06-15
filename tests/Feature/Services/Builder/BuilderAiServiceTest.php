<?php

use App\Ai\Tools\Builder\InspectRecordsTool;
use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Ai\Tools\Builder\ListAvailableFieldTypesTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\ReadManifestTool;
use App\Ai\Tools\Builder\SimulateQueryTool;
use App\Ai\Tools\Builder\ValidateManifestTool;
use App\Models\App;
use App\Models\AppVersion;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\Record;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\Integrations\IntegrationAuthoring;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Services\Storage\TenantStorage;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

function bld_id(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function bld_manifest(string $appId, string $slug = 'mini_crm'): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => $slug,
        'name' => 'Mini CRM',
        'version' => 1,
        'objects' => [[
            'id' => bld_id('obj'),
            'slug' => 'clientes',
            'name' => 'Cliente',
            'fields' => [
                ['id' => bld_id('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => [
            'roles' => [['id' => bld_id('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    $this->manifestService = app(AppManifestService::class);
    $this->validator = app(ManifestValidator::class);
    $this->manifestService->createVersion($this->testApp, bld_manifest($this->testApp->id), $this->user);
    $this->service = new BuilderAiService(
        $this->manifestService,
        $this->validator,
        app(AiProviderService::class),
        app(RecordQueryService::class),
        app(RecordWriteService::class),
        app(TenantStorage::class),
        app(AiDefaults::class),
        app(IntegrationAuthoring::class),
    );
});

it('blockSeparator only breaks when a block follows existing text without a trailing newline', function (string $buffer, bool $sawText, string $expected) {
    expect(BuilderAiService::blockSeparator($buffer, $sawText))->toBe($expected);
})->with([
    'first block of the turn' => ['', false, ''],
    'block after a tool call' => ['texto temáticas.', true, "\n\n"],
    'previous block already ended in newline' => ["texto.\n", true, ''],
    'sawText guards a stray buffer' => ['texto.', false, ''],
]);

it('startConversation creates a row scoped to the App and user', function () {
    $conv = $this->service->startConversation($this->testApp->fresh(), $this->user);

    expect($conv)->toBeInstanceOf(BuilderConversation::class)
        ->and($conv->app_id)->toBe($this->testApp->id)
        ->and($conv->user_id)->toBe($this->user->id)
        ->and($conv->status)->toBe('active')
        ->and($conv->id)->toStartWith('cnv_');
});

it('ReadManifestTool returns the active manifest as JSON envelope', function () {
    $tool = new ReadManifestTool($this->testApp->fresh(), $this->manifestService);
    $result = json_decode($tool->handle(new ToolRequest([])), true);

    expect($result['state'])->toBe('active')
        ->and($result['op_count'])->toBe(0)
        ->and($result['manifest']['slug'])->toBe('mini_crm')
        ->and($result['manifest']['objects'])->toHaveCount(1);
});

it('ReadManifestTool surfaces the running draft after a successful propose_change', function () {
    $propose = new ProposeChangeTool(
        $this->testApp->fresh(),
        $this->manifestService,
        $this->validator,
    );
    $read = new ReadManifestTool($this->testApp->fresh(), $this->manifestService, $propose);

    // Step 1: with no proposals yet, read returns the active manifest.
    $before = json_decode($read->handle(new ToolRequest([])), true);
    expect($before['state'])->toBe('active');

    // Step 2: stack one op into the running draft via recordProposal.
    $result = $propose->recordProposal(
        [['op' => 'replace', 'path' => '/name', 'value' => 'Mini CRM Updated']],
        'rename app',
    );
    expect($result['ok'])->toBeTrue();

    // Step 3: read_manifest now reflects the draft, not the persisted state.
    $after = json_decode($read->handle(new ToolRequest([])), true);
    expect($after['state'])->toBe('draft')
        ->and($after['op_count'])->toBe(1)
        ->and($after['manifest']['name'])->toBe('Mini CRM Updated');

    // And the persisted manifest hasn't moved — we haven't auto-applied yet.
    $persisted = $this->manifestService->getActiveManifest($this->testApp->fresh());
    expect($persisted['name'])->not->toBe('Mini CRM Updated');
});

it('ReadManifestTool stays on the active manifest after a failed propose_change', function () {
    $propose = new ProposeChangeTool(
        $this->testApp->fresh(),
        $this->manifestService,
        $this->validator,
    );
    $read = new ReadManifestTool($this->testApp->fresh(), $this->manifestService, $propose);

    // An op that fails schema validation (invalid value) — draft must NOT move.
    $result = $propose->recordProposal(
        [['op' => 'replace', 'path' => '/schema_version', 'value' => 12345]],
        'break it',
    );
    expect($result['ok'])->toBeFalse();

    $after = json_decode($read->handle(new ToolRequest([])), true);
    expect($after['state'])->toBe('active')
        ->and($after['op_count'])->toBe(0);
});

it('ListAvailableComponentsTool returns the closed component catalog', function () {
    $result = json_decode((new ListAvailableComponentsTool)->handle(new ToolRequest([])), true);
    $types = collect($result['components'])->pluck('type');

    expect($types)->toContain('container', 'text', 'heading', 'divider', 'spacer', 'table', 'stat', 'form', 'button', 'modal', 'chart', 'kanban', 'calendar');
});

it('ListAvailableFieldTypesTool returns the MVP field type catalog', function () {
    $result = json_decode((new ListAvailableFieldTypesTool)->handle(new ToolRequest([])), true);
    $types = collect($result['field_types'])->pluck('type');

    expect($types)->toContain('string', 'number', 'currency', 'date', 'single_select', 'relation', 'formula', 'lookup', 'rollup');
});

it('ValidateManifestTool accepts a valid manifest', function () {
    $tool = new ValidateManifestTool($this->validator);
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $result = json_decode($tool->handle(new ToolRequest(['manifest' => $manifest])), true);

    expect($result['valid'])->toBeTrue();
});

it('ValidateManifestTool reports errors with their JSONPointer paths', function () {
    $tool = new ValidateManifestTool($this->validator);
    $bad = bld_manifest($this->testApp->id);
    unset($bad['permissions']);
    $result = json_decode($tool->handle(new ToolRequest(['manifest' => $bad])), true);

    expect($result['valid'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

it('ProposeChangeTool captures a valid patch without applying it', function () {
    $tool = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    $ops = [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed']];
    $result = json_decode($tool->handle(new ToolRequest([
        'ops' => $ops, 'change_summary' => 'rename app',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($tool->lastProposal())->not->toBeNull()
        ->and($tool->lastProposal()['summary'])->toBe('rename app')
        ->and($tool->lastProposal()['draft_manifest']['name'])->toBe('Renamed');

    // No version was created — the App still has version 1.
    expect(AppVersion::query()->where('app_id', $this->testApp->id)->count())->toBe(1);
});

it('ProposeChangeTool rejects a patch that produces an invalid manifest', function () {
    $tool = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    $ops = [['op' => 'remove', 'path' => '/permissions']];
    $result = json_decode($tool->handle(new ToolRequest([
        'ops' => $ops, 'change_summary' => 'wipe permissions',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty()
        ->and($tool->lastProposal())->toBeNull();
});

it('ProposeChangeTool rejects a malformed patch', function () {
    $tool = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    $result = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'invalid_op', 'path' => '/x']],
        'change_summary' => 'bad',
    ])), true);

    expect($result['ok'])->toBeFalse();
});

it('approveProposal creates a new version and marks the message applied', function () {
    $conv = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'Proposed rename.',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed']],
        'change_summary' => 'rename',
        'status' => 'pending',
    ]);

    $version = $this->service->approveProposal($msg, $this->user);

    expect($version)->toBeInstanceOf(AppVersion::class)
        ->and($version->version_number)->toBe(2)
        ->and($version->manifest['name'])->toBe('Renamed');

    $msg->refresh();
    expect($msg->status)->toBe('applied')
        ->and($msg->applied_version_id)->toBe($version->id);
});

it('approveProposal is idempotent for an already-applied message', function () {
    $conv = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $existing = $this->manifestService->createVersion(
        $this->testApp,
        $this->manifestService->getActiveManifest($this->testApp->fresh()),
        $this->user,
    );
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'Already done.',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'X']],
        'status' => 'applied',
        'applied_version_id' => $existing->id,
    ]);

    $countBefore = AppVersion::query()->where('app_id', $this->testApp->id)->count();
    $returned = $this->service->approveProposal($msg, $this->user);
    $countAfter = AppVersion::query()->where('app_id', $this->testApp->id)->count();

    expect($returned->id)->toBe($existing->id)
        ->and($countAfter)->toBe($countBefore);
});

it('approveProposal still rejects a rejected message', function () {
    $conv = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'No.',
        'status' => 'rejected',
    ]);

    expect(fn () => $this->service->approveProposal($msg, $this->user))
        ->toThrow(DomainException::class);
});

it('revertMessage rolls back the manifest to the version before the patch landed', function () {
    $this->testApp->refresh();
    $conv = $this->service->startConversation($this->testApp, $this->user);
    // v1 is current. Apply a patch to make v2 the new current.
    $v1Manifest = $this->manifestService->getActiveManifest($this->testApp);
    $patch = [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed via Builder']];
    $v2 = $this->manifestService->applyPatch($this->testApp, $patch, $this->user, 'rename');

    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'I renamed it.',
        'proposed_patch' => $patch,
        'change_summary' => 'rename',
        'status' => 'applied',
        'applied_version_id' => $v2->id,
    ]);

    $newVersion = $this->service->revertMessage($msg, $this->user);

    // rollbackTo creates v3 with v1's manifest content as the new current.
    expect($newVersion->version_number)->toBe(3)
        ->and($newVersion->manifest['name'])->toBe($v1Manifest['name'])
        ->and($msg->refresh()->status)->toBe('reverted')
        ->and($this->testApp->fresh()->current_version_id)->toBe($newVersion->id);
});

it('revertMessage refuses if message was never applied', function () {
    $conv = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'No patch here.',
        'status' => 'none',
    ]);

    expect(fn () => $this->service->revertMessage($msg, $this->user))
        ->toThrow(DomainException::class);
});

it('revertMessage is idempotent when already reverted', function () {
    $this->testApp->refresh();
    $conv = $this->service->startConversation($this->testApp, $this->user);
    $patch = [['op' => 'replace', 'path' => '/name', 'value' => 'X']];
    $v2 = $this->manifestService->applyPatch($this->testApp, $patch, $this->user, 'x');
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'X',
        'proposed_patch' => $patch,
        'status' => 'applied',
        'applied_version_id' => $v2->id,
    ]);

    $this->service->revertMessage($msg, $this->user); // creates v3
    $countBefore = AppVersion::query()->where('app_id', $this->testApp->id)->count();
    $this->service->revertMessage($msg->refresh(), $this->user); // no-op
    $countAfter = AppVersion::query()->where('app_id', $this->testApp->id)->count();

    expect($countAfter)->toBe($countBefore);
});

it('rejectProposal marks a pending message rejected', function () {
    $conv = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => 'Proposal',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'X']],
        'status' => 'pending',
    ]);

    $this->service->rejectProposal($msg);

    expect($msg->refresh()->status)->toBe('rejected');
});

it('InspectRecordsTool returns total count and a sample for the given object', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $objectId = $manifest['objects'][0]['id'];

    foreach (['Ana', 'Beto', 'Caro', 'Dani'] as $i => $name) {
        Record::create([
            'app_id' => $this->testApp->id,
            'object_definition_id' => $objectId,
            'data' => ['nombre' => $name, 'idx' => $i],
        ]);
    }

    $tool = new InspectRecordsTool($this->testApp->fresh());
    $result = json_decode($tool->handle(new ToolRequest([
        'object_id' => $objectId,
        'limit' => 2,
    ])), true);

    expect($result['total_count'])->toBe(4)
        ->and($result['sample_rows'])->toHaveCount(2)
        ->and($result['sample_rows'][0]['data']['nombre'])->toBe('Ana');
});

it('InspectRecordsTool clamps the limit and rejects empty object_id', function () {
    $tool = new InspectRecordsTool($this->testApp->fresh());

    $clamped = json_decode($tool->handle(new ToolRequest([
        'object_id' => 'obj_'.strtolower((string) Str::ulid()),
        'limit' => 999,
    ])), true);
    expect($clamped['total_count'])->toBe(0)
        ->and($clamped['sample_rows'])->toBe([]);

    $bad = json_decode($tool->handle(new ToolRequest([])), true);
    expect($bad['error'])->toContain('object_id');
});

it('SimulateQueryTool returns count + sample rows for a valid query', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $objectId = $manifest['objects'][0]['id'];
    $nombreFieldId = $manifest['objects'][0]['fields'][0]['id'];

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objectId, 'data' => ['nombre' => 'Ana']]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objectId, 'data' => ['nombre' => 'Beto']]);

    $tool = new SimulateQueryTool($this->testApp->fresh(), $this->manifestService, app(RecordQueryService::class));
    $result = json_decode($tool->handle(new ToolRequest([
        'query' => [
            'object_id' => $objectId,
            'filter' => ['op' => 'eq', 'field_id' => $nombreFieldId, 'value' => 'Ana'],
        ],
    ])), true);

    expect($result['count'])->toBe(1)
        ->and($result['sample_rows'])->toHaveCount(1)
        ->and($result['sample_rows'][0]['data']['nombre'])->toBe('Ana');
});

it('SimulateQueryTool reports aggregation_value when aggregation arg is given', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $objectId = $manifest['objects'][0]['id'];

    // Add a currency field to the object so we can aggregate on it.
    $montoFieldId = 'fld_'.strtolower((string) Str::ulid());
    $patchedManifest = $manifest;
    $patchedManifest['objects'][0]['fields'][] = [
        'id' => $montoFieldId, 'slug' => 'monto', 'name' => 'Monto',
        'type' => 'currency', 'currency_code' => 'MXN',
    ];
    $this->manifestService->createVersion($this->testApp, $patchedManifest);

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objectId, 'data' => ['monto' => 100]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $objectId, 'data' => ['monto' => 250]]);

    $tool = new SimulateQueryTool($this->testApp->fresh(), $this->manifestService, app(RecordQueryService::class));
    $result = json_decode($tool->handle(new ToolRequest([
        'query' => ['object_id' => $objectId],
        'aggregation' => 'sum',
        'field_id' => $montoFieldId,
    ])), true);

    expect($result['count'])->toBe(2)
        ->and($result['aggregation'])->toBe('sum')
        ->and((float) $result['aggregation_value'])->toBe(350.0);
});

it('SimulateQueryTool returns errors on bad input instead of throwing', function () {
    $tool = new SimulateQueryTool($this->testApp->fresh(), $this->manifestService, app(RecordQueryService::class));

    $noObj = json_decode($tool->handle(new ToolRequest(['query' => []])), true);
    expect($noObj['errors'][0]['code'])->toBe('bad_input');

    $unknownField = json_decode($tool->handle(new ToolRequest([
        'query' => [
            'object_id' => 'obj_'.strtolower((string) Str::ulid()),
            'filter' => ['op' => 'eq', 'field_id' => 'fld_x', 'value' => 1],
        ],
    ])), true);
    expect($unknownField['errors'][0]['code'])->toBe('query_failed');
});

it('ProposeChangeTool accumulates ops across multiple calls in the same turn', function () {
    $tool = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    // Call 1: rename the app.
    $r1 = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed App']],
        'change_summary' => 'rename app',
    ])), true);
    expect($r1['ok'])->toBeTrue();

    // Call 2: add a new object. This must validate against the renamed draft
    // (call #1) and combine its ops with the accumulated patch.
    $newObjId = 'obj_'.strtolower((string) Str::ulid());
    $newFldId = 'fld_'.strtolower((string) Str::ulid());
    $r2 = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'add', 'path' => '/objects/-', 'value' => [
            'id' => $newObjId, 'slug' => 'orders', 'name' => 'Order',
            'fields' => [
                ['id' => $newFldId, 'slug' => 'numero', 'name' => 'Número', 'type' => 'string'],
            ],
        ]]],
        'change_summary' => 'add orders object',
    ])), true);

    expect($r2['ok'])->toBeTrue()
        ->and($r2['total_op_count'])->toBe(2);

    $proposal = $tool->lastProposal();
    expect($proposal['patch'])->toHaveCount(2)
        ->and($proposal['draft_manifest']['name'])->toBe('Renamed App')
        ->and($proposal['draft_manifest']['objects'])->toHaveCount(2) // original clientes + new orders
        ->and($proposal['summary'])->toContain('rename app')
        ->and($proposal['summary'])->toContain('add orders object');
});

it('ProposeChangeTool: second call validates against the FIRST call draft, not the live manifest', function () {
    // This is the "add a field, then reference it from a form" scenario that
    // broke the user — the form ops referenced a field that only existed in
    // the first call's draft.
    $tool = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    $objId = 'obj_'.strtolower((string) Str::ulid());
    $fldId = 'fld_'.strtolower((string) Str::ulid());

    // Call 1: add a brand-new object with one field.
    $r1 = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'add', 'path' => '/objects/-', 'value' => [
            'id' => $objId, 'slug' => 'orders', 'name' => 'Order',
            'fields' => [['id' => $fldId, 'slug' => 'numero', 'name' => 'N', 'type' => 'string']],
        ]]],
        'change_summary' => 'add object',
    ])), true);
    expect($r1['ok'])->toBeTrue();

    // Call 2: add a page with a TABLE that references the freshly-added field
    // from call #1. Pre-accumulation behaviour would 404 the field; with
    // accumulation, call #2 sees the field already in the running draft.
    $r2 = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'add', 'path' => '/pages/-', 'value' => [
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'orders',
            'name' => 'Orders',
            'path' => '/orders',
            'blocks' => [[
                'id' => 'blk_'.strtolower((string) Str::ulid()),
                'type' => 'table',
                'data_source' => ['object_id' => $objId],
                'columns' => [['id' => 'col_'.strtolower((string) Str::ulid()), 'field_id' => $fldId]],
            ]],
        ]]],
        'change_summary' => 'add page',
    ])), true);

    expect($r2['ok'])->toBeTrue()
        ->and($r2['total_op_count'])->toBe(2);
});

it('ProposeChangeTool: a failed second call leaves the first draft intact', function () {
    $tool = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    $r1 = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'replace', 'path' => '/name', 'value' => 'Good Rename']],
        'change_summary' => 'rename',
    ])), true);
    expect($r1['ok'])->toBeTrue();

    // Bad call: invalid patch.
    $r2 = json_decode($tool->handle(new ToolRequest([
        'ops' => [['op' => 'remove', 'path' => '/objects']], // removes a required key
        'change_summary' => 'oops',
    ])), true);
    expect($r2['ok'])->toBeFalse();

    // The proposal kept from call #1 is still the rename — call #2's failure
    // did not corrupt the running draft.
    $proposal = $tool->lastProposal();
    expect($proposal['patch'])->toHaveCount(1)
        ->and($proposal['draft_manifest']['name'])->toBe('Good Rename')
        ->and($proposal['summary'])->toBe('rename');
});
