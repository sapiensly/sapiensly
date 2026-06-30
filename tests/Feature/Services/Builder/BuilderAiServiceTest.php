<?php

use App\Ai\Tools\Builder\AddCrudPageTool;
use App\Ai\Tools\Builder\AddDetailPageTool;
use App\Ai\Tools\Builder\FrameworkReferenceTool;
use App\Ai\Tools\Builder\GeneratePaletteTool;
use App\Ai\Tools\Builder\InspectRecordsTool;
use App\Ai\Tools\Builder\ListAvailableComponentsTool;
use App\Ai\Tools\Builder\ListAvailableFieldTypesTool;
use App\Ai\Tools\Builder\ProfileObjectTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Ai\Tools\Builder\ReadManifestTool;
use App\Ai\Tools\Builder\ScaffoldAppTool;
use App\Ai\Tools\Builder\SetBuildPlanTool;
use App\Ai\Tools\Builder\SimulateQueryTool;
use App\Ai\Tools\Builder\TargetPlanStepsTool;
use App\Ai\Tools\Builder\ValidateManifestTool;
use App\Jobs\RunBuilderAiJob;
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
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;
use App\Services\Storage\TenantStorage;
use Illuminate\Support\Facades\Queue;
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
        ->and($result['summary']['slug'])->toBe('mini_crm')
        ->and($result['summary']['objects'])->toHaveCount(1);

    // The active-state note must NOT let the model read "active" as "my change
    // landed" — it has to say nothing is drafted and propose_change is required.
    expect($result['note'])->toContain('NOT proposed any change')
        ->and($result['note'])->toContain('propose_change');
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
        ->and($after['summary']['name'])->toBe('Mini CRM Updated');

    // And the persisted manifest hasn't moved — we haven't auto-applied yet.
    $persisted = $this->manifestService->getActiveManifest($this->testApp->fresh());
    expect($persisted['name'])->not->toBe('Mini CRM Updated');
});

it('ScaffoldAppTool builds a valid object + list page from a high-level spec', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);
    $tool = new ScaffoldAppTool($this->testApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest([
        'objects' => [
            [
                'name' => 'Leads',
                'fields' => [
                    ['name' => 'Nombre', 'type' => 'string'],
                    ['name' => 'Teléfono', 'type' => 'phone'],
                    ['name' => 'Estado', 'type' => 'single_select', 'options' => ['Nuevo', 'Contactado', 'Ganado']],
                ],
            ],
        ],
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['created'])->toHaveCount(1)
        ->and($result['created'][0]['slug'])->toBe('leads')
        ->and($result['created'][0])->toHaveKey('page_id');

    // The running draft holds a fully valid manifest with the new object + page.
    $draft = $propose->runningDraft();
    $newObject = collect($draft['objects'])->firstWhere('slug', 'leads');
    expect($newObject)->not->toBeNull()
        ->and($newObject['fields'])->toHaveCount(3);

    // The select field used value+label (the shape models keep getting wrong).
    $estado = collect($newObject['fields'])->firstWhere('slug', 'estado');
    expect($estado['type'])->toBe('single_select')
        ->and($estado['options'][0])->toHaveKeys(['id', 'value', 'label'])
        ->and($estado['options'][0]['value'])->toBe('nuevo')
        ->and($estado['options'][0]['label'])->toBe('Nuevo');

    // And the whole assembled manifest passes validation.
    expect($this->validator->validate($draft)->valid)->toBeTrue();
});

it('ScaffoldAppTool gives an object with no fields a default field, and coerces unsupported types', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);
    $tool = new ScaffoldAppTool($this->testApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest([
        'objects' => [
            ['name' => 'Notas'],
            ['name' => 'Pedidos', 'fields' => [['name' => 'Cliente', 'type' => 'relation']]],
        ],
        'include_pages' => false,
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['created'])->toHaveCount(2)
        ->and($result['created'][0])->not->toHaveKey('page_id') // include_pages=false
        ->and($result['notes'])->not->toBeEmpty();

    $draft = $propose->runningDraft();
    $notas = collect($draft['objects'])->firstWhere('slug', 'notas');
    $pedidos = collect($draft['objects'])->firstWhere('slug', 'pedidos');
    expect($notas['fields'])->toHaveCount(1) // default field added
        ->and(collect($pedidos['fields'])->firstWhere('name', 'Cliente')['type'])->toBe('string'); // relation coerced

    expect($this->validator->validate($draft)->valid)->toBeTrue();
});

it('ScaffoldAppTool cold-starts a full app (relations + derived + POS) from objects and links', function () {
    // A brand-new EMPTY app: the tool should assemble the WHOLE thing in one shot.
    $emptyApp = App::factory()->create();
    $this->manifestService->createVersion($emptyApp, [
        'schema_version' => '1.0.0', 'id' => $emptyApp->id, 'slug' => 'pos_cold', 'name' => 'POS', 'version' => 1,
        'objects' => [], 'pages' => [],
        'permissions' => ['roles' => [['id' => bld_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => ['default_locale' => 'es-MX', 'default_currency' => 'MXN'],
    ], $this->user);

    $propose = new ProposeChangeTool($emptyApp->fresh(), $this->manifestService, $this->validator);
    $tool = new ScaffoldAppTool($emptyApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest([
        'objects' => [
            ['name' => 'Comandas', 'slug' => 'comandas', 'fields' => [
                ['name' => 'Folio', 'type' => 'string'],
                ['name' => 'Estado', 'type' => 'single_select', 'options' => ['Abierta', 'Pagada']],
            ]],
            ['name' => 'Platillos', 'slug' => 'platillos', 'fields' => [
                ['name' => 'Nombre', 'type' => 'string'],
                ['name' => 'Precio', 'type' => 'currency'],
                ['name' => 'Imagen', 'type' => 'string'],
            ]],
            ['name' => 'Renglones', 'slug' => 'renglones', 'fields' => [
                ['name' => 'Cantidad', 'type' => 'number'],
            ]],
        ],
        'links' => [
            ['from' => 'renglones', 'to' => 'comandas', 'name' => 'comanda'],
            ['from' => 'renglones', 'to' => 'platillos', 'name' => 'platillo'],
        ],
    ])), true);

    expect($result['ok'])->toBeTrue();
    $draft = $propose->runningDraft();

    // The POS screen was generated from the order→line→priced-product shape.
    expect(collect($draft['pages'])->firstWhere('slug', 'pos'))->not->toBeNull();

    // The line got its derived economics: a unit-price lookup + a subtotal formula.
    $renglones = collect($draft['objects'])->firstWhere('slug', 'renglones');
    expect(collect($renglones['fields'])->firstWhere('type', 'lookup'))->not->toBeNull();
    expect(collect($renglones['fields'])->firstWhere('type', 'formula'))->not->toBeNull();

    // …and the whole assembled manifest is valid.
    expect($this->validator->validate($draft)->valid)->toBeTrue();
});

it('ScaffoldAppTool rejects an empty objects list', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);
    $tool = new ScaffoldAppTool($this->testApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest(['objects' => []])), true);

    expect($result['ok'])->toBeFalse();
});

it('add_crud_page builds a full list page for an existing object', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);
    $tool = new AddCrudPageTool($this->testApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest(['object_slug' => 'clientes'])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['page']['slug'])->toBe('clientes');
    expect($result['page']['path'])->toBe('/clientes');

    $draft = $propose->runningDraft();
    $page = collect($draft['pages'])->firstWhere('slug', 'clientes');
    expect($page)->not->toBeNull();

    $types = collect($page['blocks'])->pluck('type');
    expect($types)->toContain('table');
    // The "new" modal carries a create form for the object's fields.
    expect(json_encode($page))->toContain('"type":"form"');

    expect($this->validator->validate($draft)->valid)->toBeTrue();
});

it('add_crud_page fails for an unknown object slug', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);
    $tool = new AddCrudPageTool($this->testApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest(['object_slug' => 'nope'])), true);

    expect($result['ok'])->toBeFalse();
});

it('add_detail_page builds a master-detail page and links the parent list table', function () {
    // A parent (comandas) with a child (renglones) that belongs to it, plus a
    // pre-existing list page whose table is over the parent.
    $comId = bld_id('obj');
    $renId = bld_id('obj');
    $comFolio = bld_id('fld');
    $renCant = bld_id('fld');
    $renRel = bld_id('fld');

    $detailApp = App::factory()->create();
    $this->manifestService->createVersion($detailApp, [
        'schema_version' => '1.0.0', 'id' => $detailApp->id, 'slug' => 'pos_md', 'name' => 'POS', 'version' => 1,
        'objects' => [
            ['id' => $comId, 'slug' => 'comandas', 'name' => 'Comandas', 'fields' => [
                ['id' => $comFolio, 'slug' => 'folio', 'name' => 'Folio', 'type' => 'string'],
            ]],
            ['id' => $renId, 'slug' => 'renglones', 'name' => 'Renglones', 'fields' => [
                ['id' => $renCant, 'slug' => 'cantidad', 'name' => 'Cantidad', 'type' => 'number'],
                ['id' => $renRel, 'slug' => 'comanda', 'name' => 'Comanda', 'type' => 'relation', 'target_object_id' => $comId, 'cardinality' => 'many_to_one'],
            ]],
        ],
        'pages' => [[
            'id' => bld_id('pag'), 'slug' => 'comandas', 'name' => 'Comandas', 'path' => '/comandas',
            'blocks' => [[
                'id' => bld_id('blk'), 'type' => 'table',
                'data_source' => ['object_id' => $comId],
                'columns' => [['id' => bld_id('col'), 'field_id' => $comFolio]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => bld_id('rol'), 'slug' => 'admin', 'name' => 'Admin']]],
        'settings' => ['default_locale' => 'es-MX'],
    ], $this->user);

    $propose = new ProposeChangeTool($detailApp->fresh(), $this->manifestService, $this->validator);
    $tool = new AddDetailPageTool($detailApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    $result = json_decode($tool->handle(new ToolRequest(['object_slug' => 'comandas'])), true);

    expect($result['ok'])->toBeTrue();
    expect($result['page']['slug'])->toBe('comandas_detail');

    $draft = $propose->runningDraft();
    $detail = collect($draft['pages'])->firstWhere('slug', 'comandas_detail');
    expect($detail)->not->toBeNull();

    $types = collect($detail['blocks'])->pluck('type');
    expect($types)->toContain('record_detail');
    expect($types)->toContain('related_list');

    // The parent's existing list table got an "open" action column linking here.
    $listPage = collect($draft['pages'])->firstWhere('slug', 'comandas');
    $table = collect($listPage['blocks'])->firstWhere('type', 'table');
    $actionCol = collect($table['columns'])->firstWhere('type', 'action');
    expect($actionCol)->not->toBeNull();
    expect(json_encode($actionCol))->toContain('comandas_detail?id={{row.id}}');

    expect($this->validator->validate($draft)->valid)->toBeTrue();
});

it('add_detail_page fails when the object has no children', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);
    $tool = new AddDetailPageTool($this->testApp->fresh(), $this->manifestService, $propose, app(AppScaffolder::class));

    // clientes has no object pointing back at it.
    $result = json_decode($tool->handle(new ToolRequest(['object_slug' => 'clientes'])), true);

    expect($result['ok'])->toBeFalse();
});

it('set_build_plan creates a plan on the conversation with minted ids', function () {
    $conv = $this->service->startConversation($this->testApp, $this->user);
    $tool = new SetBuildPlanTool($conv);

    $result = json_decode($tool->handle(new ToolRequest([
        'goal' => 'Punto de venta',
        'steps' => [['title' => 'Objetos y relaciones'], ['title' => 'Páginas']],
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['plan']['steps'])->toHaveCount(2);

    $conv->refresh();
    expect($conv->build_plan['goal'])->toBe('Punto de venta')
        ->and($conv->build_plan['steps'][0]['id'])->toStartWith('stp_')
        ->and($conv->build_plan['steps'][0]['status'])->toBe('pending');
});

it('target_plan_steps marks steps in_progress and rejects unknown ids', function () {
    $conv = $this->service->startConversation($this->testApp, $this->user);
    (new SetBuildPlanTool($conv))->handle(new ToolRequest([
        'steps' => [['title' => 'A'], ['title' => 'B']],
    ]));
    $conv->refresh();
    $idA = $conv->build_plan['steps'][0]['id'];

    $tool = new TargetPlanStepsTool($conv);
    $ok = json_decode($tool->handle(new ToolRequest(['step_ids' => [$idA]])), true);
    expect($ok['ok'])->toBeTrue()
        ->and($ok['in_progress'])->toContain($idA);

    $bad = json_decode($tool->handle(new ToolRequest(['step_ids' => ['stp_nope']])), true);
    expect($bad['ok'])->toBeFalse();
});

it('target_plan_steps fails when there is no plan yet', function () {
    $conv = $this->service->startConversation($this->testApp, $this->user);
    $result = json_decode((new TargetPlanStepsTool($conv))->handle(new ToolRequest(['step_ids' => ['stp_x']])), true);

    expect($result['ok'])->toBeFalse();
});

it('revertMessage reopens build-plan steps closed by the reverted version', function () {
    $conv = $this->service->startConversation($this->testApp, $this->user);

    // A real applied version (v2) on top of the beforeEach v1.
    $v2 = $this->manifestService->applyPatch(
        $this->testApp->fresh(),
        [['op' => 'replace', 'path' => '/name', 'value' => 'Renamed']],
        $this->user,
        'rename',
    );

    // A plan step already closed by v2.
    $conv->update(['build_plan' => [
        'schema' => 1, 'goal' => null, 'status' => 'done', 'steps' => [[
            'id' => 'stp_a', 'title' => 'paso', 'detail' => null, 'status' => 'done',
            'applied_version_id' => $v2->id, 'version_number' => $v2->version_number,
            'closed_by_summary' => 'rename', 'error' => null,
        ]],
    ]]);

    $msg = BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'ok',
        'status' => 'applied', 'applied_version_id' => $v2->id,
    ]);

    $this->service->revertMessage($msg, $this->user);

    $conv->refresh();
    expect($conv->build_plan['steps'][0]['status'])->toBe('pending')
        ->and($conv->build_plan['steps'][0]['applied_version_id'])->toBeNull()
        ->and($conv->build_plan['status'])->toBe('active');
});

it('autonomousDecision continues only when budget remains, plan is active, and the turn progressed', function () {
    $activePlan = ['status' => 'active', 'steps' => [['id' => 'stp_a', 'status' => 'pending']]];

    expect($this->service->autonomousDecision($activePlan, 'applied', ['stp_a'], 5)['continue'])->toBeTrue();
    expect($this->service->autonomousDecision($activePlan, 'applied', ['stp_a'], 0))
        ->toMatchArray(['continue' => false, 'reason' => 'cap']);
    expect($this->service->autonomousDecision(['status' => 'done', 'steps' => []], 'applied', ['stp_a'], 5))
        ->toMatchArray(['continue' => false, 'reason' => 'plan_complete']);
    expect($this->service->autonomousDecision(null, 'applied', ['stp_a'], 5))
        ->toMatchArray(['continue' => false, 'reason' => 'plan_complete']);
    // Applied but closed no step, or no proposal at all → halt (anti-runaway).
    expect($this->service->autonomousDecision($activePlan, 'applied', [], 5))
        ->toMatchArray(['continue' => false, 'reason' => 'no_progress']);
    expect($this->service->autonomousDecision($activePlan, 'none', null, 5))
        ->toMatchArray(['continue' => false, 'reason' => 'no_progress']);
});

it('continueAutonomously queues the next turn when the plan advanced', function () {
    Queue::fake();
    $conv = $this->service->startConversation($this->testApp, $this->user);
    $conv->update(['build_plan' => ['schema' => 1, 'goal' => null, 'status' => 'active', 'steps' => [
        ['id' => 'stp_a', 'title' => 'A', 'detail' => null, 'status' => 'done', 'applied_version_id' => 'apv_1', 'version_number' => 2, 'closed_by_summary' => null, 'error' => null],
        ['id' => 'stp_b', 'title' => 'B', 'detail' => null, 'status' => 'pending', 'applied_version_id' => null, 'version_number' => null, 'closed_by_summary' => null, 'error' => null],
    ]]]);

    $finished = BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'hecho A',
        'status' => 'applied', 'applied_version_id' => 'apv_1', 'plan_step_ids' => ['stp_a'],
    ]);

    $this->service->continueAutonomously($finished, 5, 'claude-haiku-4-5-20251001');

    Queue::assertPushed(RunBuilderAiJob::class, fn (RunBuilderAiJob $job) => $job->autonomousRemaining === 4
        && $job->modelOverride === 'claude-haiku-4-5-20251001');
    // A fresh user turn + assistant placeholder were created for the next step.
    expect($conv->messages()->where('role', 'user')->where('content', 'like', '%autónomo%')->exists())->toBeTrue();
});

it('build_plan_status mirrors the plan status and withActivePlan finds open plans', function () {
    $conv = $this->service->startConversation($this->testApp, $this->user);
    $conv->update(['build_plan' => ['schema' => 1, 'status' => 'active', 'steps' => [
        ['id' => 'stp_a', 'title' => 'A', 'status' => 'pending'],
    ]]]);

    expect($conv->fresh()->build_plan_status)->toBe('active')
        ->and(BuilderConversation::withActivePlan()->whereKey($conv->id)->exists())->toBeTrue();

    $conv->update(['build_plan' => ['schema' => 1, 'status' => 'done', 'steps' => []]]);

    expect($conv->fresh()->build_plan_status)->toBe('done')
        ->and(BuilderConversation::withActivePlan()->whereKey($conv->id)->exists())->toBeFalse();
});

it('continueAutonomously halts and notes when the turn did not advance the plan', function () {
    Queue::fake();
    $conv = $this->service->startConversation($this->testApp, $this->user);
    $conv->update(['build_plan' => ['schema' => 1, 'goal' => null, 'status' => 'active', 'steps' => [
        ['id' => 'stp_a', 'title' => 'A', 'detail' => null, 'status' => 'pending', 'applied_version_id' => null, 'version_number' => null, 'closed_by_summary' => null, 'error' => null],
    ]]]);

    // A phantom turn: applied nothing, closed no step.
    $finished = BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant', 'content' => 'dije que lo hice',
        'status' => 'none', 'applied_version_id' => null, 'plan_step_ids' => null,
    ]);

    $this->service->continueAutonomously($finished, 5, null);

    Queue::assertNotPushed(RunBuilderAiJob::class);
    expect($conv->messages()->where('content', 'like', '%no avanzó%')->exists())->toBeTrue();
});

it('ProposeChangeTool fires the onProgress checkpoint only on a successful proposal', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    $checkpoints = [];
    $propose->onProgress(function (array $proposal) use (&$checkpoints): void {
        $checkpoints[] = $proposal;
    });

    // A failing op must NOT checkpoint.
    $propose->recordProposal([['op' => 'replace', 'path' => '/schema_version', 'value' => 999]], 'bad');
    expect($checkpoints)->toBeEmpty();

    // A valid op checkpoints the accumulated patch + summary.
    $propose->recordProposal([['op' => 'replace', 'path' => '/name', 'value' => 'Renamed']], 'rename');
    expect($checkpoints)->toHaveCount(1)
        ->and($checkpoints[0]['patch'])->toHaveCount(1)
        ->and($checkpoints[0]['summary'])->toBe('rename');

    // A second valid op checkpoints the STACKED patch.
    $propose->recordProposal([['op' => 'replace', 'path' => '/version', 'value' => 2]], 'bump');
    expect($checkpoints)->toHaveCount(2)
        ->and($checkpoints[1]['patch'])->toHaveCount(2);
});

it('applyCheckpoint banks a checkpointed patch from an interrupted turn as a new version', function () {
    $conversation = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $message = BuilderMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Recovered CRM']],
        'change_summary' => 'rename app',
    ]);

    $version = $this->service->applyCheckpoint($message);

    expect($version)->not->toBeNull()
        ->and($message->fresh()->status)->toBe('applied')
        ->and($message->fresh()->applied_version_id)->toBe($version->id)
        ->and($this->manifestService->getActiveManifest($this->testApp->fresh())['name'])->toBe('Recovered CRM');

    // Idempotent: re-running on an applied message banks nothing more.
    expect($this->service->applyCheckpoint($message->fresh()))->toBeNull();
});

it('applyCheckpoint returns null when there is no checkpointed work', function () {
    $conversation = $this->service->startConversation($this->testApp->fresh(), $this->user);
    $message = BuilderMessage::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
    ]);

    expect($this->service->applyCheckpoint($message))->toBeNull();
});

it('commitTurn applies a valid proposal as a new version', function () {
    $commit = $this->service->commitTurn(
        $this->testApp->fresh(),
        ['patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Committed CRM']], 'summary' => 'rename'],
        $this->user,
        'Listo, renombré la app.',
    );

    expect($commit['status'])->toBe('applied')
        ->and($commit['applied_version_id'])->not->toBeNull()
        ->and($commit['error'])->toBeNull()
        ->and($commit['content'])->toBe('Listo, renombré la app.')
        ->and($this->manifestService->getActiveManifest($this->testApp->fresh())['name'])->toBe('Committed CRM');
});

it('commitTurn surfaces a failed apply instead of silently reporting success', function () {
    // A patch whose RESULT is invalid (schema_version must be \d+\.\d+\.\d+):
    // the model validated its draft inside the tool loop, but persisting the
    // accumulated patch throws — this must not be swallowed.
    $buffer = 'Listo. Creé el mini CRM con todo lo que pediste.';
    $nameBefore = $this->manifestService->getActiveManifest($this->testApp->fresh())['name'];
    $commit = $this->service->commitTurn(
        $this->testApp->fresh(),
        ['patch' => [['op' => 'replace', 'path' => '/schema_version', 'value' => 'not-a-version']], 'summary' => 'broken'],
        $this->user,
        $buffer,
    );

    expect($commit['status'])->toBe('none')
        ->and($commit['applied_version_id'])->toBeNull()
        ->and($commit['error'])->not->toBeNull()
        ->and($commit['proposed_patch'])->not->toBeNull()
        ->and($commit['content'])->toStartWith($buffer)
        ->and($commit['content'])->toContain('Changes were not saved')
        ->and($this->manifestService->getActiveManifest($this->testApp->fresh())['name'])->toBe($nameBefore);
});

it('commitTurn is a quiet no-op when the turn produced no proposal', function () {
    $commit = $this->service->commitTurn(
        $this->testApp->fresh(),
        null,
        $this->user,
        'Sure, here is how qualification works…',
    );

    expect($commit['status'])->toBe('none')
        ->and($commit['error'])->toBeNull()
        ->and($commit['proposed_patch'])->toBeNull()
        ->and($commit['content'])->toBe('Sure, here is how qualification works…');
});

it('save-failure reconcile prompt carries the raw apply error for the model to own', function () {
    $prompt = BuilderAiService::saveFailureReconcilePrompt('permission denied for schema platform');

    expect($prompt)->toContain('permission denied for schema platform')
        ->and($prompt)->toContain('not from the user')
        ->and($prompt)->toContain('not changed');
});

it('save-failure reconcile instructions forbid claiming success and pin the language', function () {
    $instructions = BuilderAiService::saveFailureReconcileInstructions();

    expect($instructions)->toContain('NOT saved')
        ->and($instructions)->toContain('Do NOT claim')
        ->and($instructions)->toContain('SAME language');
});

it('ReadManifestTool returns one element in full when expanded', function () {
    $tool = new ReadManifestTool($this->testApp->fresh(), $this->manifestService);

    $summary = json_decode($tool->handle(new ToolRequest([])), true);
    $objectId = $summary['summary']['objects'][0]['id'];

    $expanded = json_decode($tool->handle(new ToolRequest(['expand' => $objectId])), true);
    expect($expanded['expanded'])->toBe($objectId)
        ->and($expanded['element']['id'])->toBe($objectId)
        ->and($expanded['element'])->toHaveKey('fields'); // full subtree, not just the summary
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

it('FrameworkReferenceTool lists its topics when called with no topic', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest([])), true);

    expect($result['topics'])->toContain('forms', 'workflows', 'derived_fields', 'expressions', 'design', 'palette', 'icons', 'custom_css', 'permissions', 'verification', 'visual_review', 'connected_objects', 'example');
});

it('generate_palette derives a palette from a base accent', function () {
    $result = json_decode((new GeneratePaletteTool)->handle(new ToolRequest(['base' => '#0096ff'])), true);

    expect($result['base'])->toBe('#0096ff')
        ->and($result['ramp']['500'])->toBe('#0096ff')
        ->and($result['chart'])->toHaveCount(6)
        ->and($result['css_variables']['chart'])->toContain('--sp-chart');
});

it('FrameworkReferenceTool documents named icons + emoji for any block icon', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'icons'])), true);

    expect($result['topic'])->toBe('icons')
        ->and($result['reference'])->toContain('list_available_icons')
        ->and($result['reference'])->toContain('button.icon');
});

it('FrameworkReferenceTool documents the scoped custom_css escape hatch', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'custom_css'])), true);

    expect($result['topic'])->toBe('custom_css')
        ->and($result['reference'])->toContain('.sp-app-surface')
        ->and($result['reference'])->toContain('data-block-type')
        ->and($result['reference'])->toContain('var(--sp-accent');
});

it('FrameworkReferenceTool documents the enforced access layer under permissions', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'permissions'])), true);

    expect($result['topic'])->toBe('permissions')
        ->and($result['reference'])->toContain('access_mode')
        ->and($result['reference'])->toContain('allowlist')
        ->and($result['reference'])->toContain('row_filter')
        ->and($result['reference'])->toContain('field_restrictions')
        ->and($result['reference'])->toContain('page_policies')
        ->and($result['reference'])->toContain('is_default')
        ->and($result['reference'])->toContain('ENFORCED');
});

it('FrameworkReferenceTool documents the data/query model and the authoring limit', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'data'])), true);

    expect($result['topic'])->toBe('data')
        // Runtime query powers are named…
        ->and($result['reference'])->toContain('describe_app_data')
        ->and($result['reference'])->toContain('related')
        ->and($result['reference'])->toContain('expand')
        ->and($result['reference'])->toContain('group_by')
        // …and the authoring limit steers to derived fields instead.
        ->and($result['reference'])->toContain('lookup')
        ->and($result['reference'])->toContain('rollup');
});

it('FrameworkReferenceTool permissions snippet validates inside a manifest', function () {
    $reference = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'permissions'])), true)['reference'];

    // The topic ends with one JSON object: {\n  "permissions": {…}\n}. Anchor on
    // that block's opening brace (prose above contains other braces like
    // {{current_user.id}}), then take everything through the final brace.
    $start = strpos($reference, "{\n  \"permissions\"");
    $end = strrpos($reference, '}');
    $snippet = json_decode(substr($reference, $start, $end - $start + 1), true);

    expect($snippet)->toHaveKey('permissions');

    // Wrap the documented permissions block in a manifest whose objects/fields/
    // pages match the ids it references, so referential validation runs for real.
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_referencepermissions',
        'slug' => 'perm_demo',
        'name' => 'Permissions Demo',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_ticketsobject',
            'slug' => 'tickets',
            'name' => 'Ticket',
            'fields' => [
                ['id' => 'fld_ownerfield01', 'slug' => 'owner', 'name' => 'Owner', 'type' => 'string'],
                ['id' => 'fld_internalnotes01', 'slug' => 'internal_notes', 'name' => 'Internal notes', 'type' => 'long_text'],
            ],
        ]],
        'pages' => [
            ['id' => 'pag_adminpage01', 'slug' => 'admin', 'name' => 'Admin', 'path' => '/admin', 'blocks' => []],
        ],
        'permissions' => $snippet['permissions'],
    ];

    $result = app(ManifestValidator::class)->validate($manifest);

    expect($result->valid)->toBeTrue(collect($result->errors)->pluck('message')->implode("\n"));
});

it('FrameworkReferenceTool returns the requested topic section', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'workflows'])), true);

    expect($result['topic'])->toBe('workflows')
        ->and($result['reference'])->toContain('script.run')
        ->and($result['reference'])->toContain('CONTEXT BOUNDARY');
});

it('FrameworkReferenceTool example manifest actually validates', function () {
    $reference = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'example'])), true)['reference'];

    $start = strpos($reference, '{');
    $end = strrpos($reference, '}');
    $manifest = json_decode(substr($reference, $start, $end - $start + 1), true);

    expect($manifest)->not->toBeNull('the embedded example must be parseable JSON');

    $result = app(ManifestValidator::class)->validate($manifest);

    expect($result->valid)->toBeTrue(collect($result->errors)->pluck('message')->implode("\n"));
});

it('FrameworkReferenceTool rejects an unknown topic and lists valid ones', function () {
    $result = json_decode((new FrameworkReferenceTool)->handle(new ToolRequest(['topic' => 'nonsense'])), true);

    expect($result['error'])->toContain('nonsense')
        ->and($result['topics'])->toContain('forms');
});

it('ListAvailableComponentsTool returns the closed component catalog', function () {
    $result = json_decode((new ListAvailableComponentsTool)->handle(new ToolRequest([])), true);
    $types = collect($result['components'])->pluck('type');

    expect($types)->toContain('container', 'text', 'heading', 'divider', 'spacer', 'table', 'stat', 'form', 'button', 'modal', 'chart', 'kanban', 'calendar')
        ->and($types)->toContain('alert', 'avatar', 'breadcrumb', 'carousel', 'badge', 'stepper'); // newer UI blocks
});

it('ListAvailableFieldTypesTool includes the color field type', function () {
    $result = json_decode((new ListAvailableFieldTypesTool)->handle(new ToolRequest([])), true);

    expect(collect($result['field_types'])->pluck('type'))->toContain('color');
});

it('validates a sidebar layout with nested navigation', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_sidebarnav01',
        'slug' => 'sidebarnav',
        'name' => 'Sidebar Nav',
        'version' => 1,
        'objects' => [],
        'pages' => [
            ['id' => 'pag_dashboard01', 'slug' => 'dashboard', 'name' => 'Dashboard', 'path' => '/dashboard', 'blocks' => []],
            ['id' => 'pag_reportspg01', 'slug' => 'reports', 'name' => 'Reports', 'path' => '/reports', 'blocks' => []],
        ],
        'permissions' => ['roles' => [['id' => 'rol_adminrole01', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['navigation_layout' => 'sidebar'],
        'navigation' => ['items' => [
            ['id' => 'nav_dashboard01', 'label' => 'Dashboard', 'icon' => 'dashboard', 'page_id' => 'pag_dashboard01'],
            ['id' => 'nav_analyticsg', 'label' => 'Analytics', 'icon' => 'bar-chart', 'children' => [
                ['id' => 'nav_reportsitm', 'label' => 'Reports', 'page_id' => 'pag_reportspg01'],
            ]],
        ]],
    ];

    $result = app(ManifestValidator::class)->validate($manifest);

    expect($result->valid)->toBeTrue(collect($result->errors)->pluck('message')->implode("\n"));
});

it('validates a manifest using the new UI blocks + table pagination', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_newblocks01',
        'slug' => 'newblocks',
        'name' => 'New Blocks',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_itemsobject', 'slug' => 'items', 'name' => 'Item',
            'fields' => [
                ['id' => 'fld_namefield01', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ['id' => 'fld_colorfield1', 'slug' => 'color', 'name' => 'Colour', 'type' => 'color', 'default' => '#0096ff'],
                ['id' => 'fld_activefield', 'slug' => 'active', 'name' => 'Active', 'type' => 'boolean', 'display' => 'switch'],
                ['id' => 'fld_statusfield', 'slug' => 'status', 'name' => 'Status', 'type' => 'single_select', 'display' => 'radio', 'options' => [
                    ['id' => 'opt_newoption01', 'value' => 'new', 'label' => 'New'],
                    ['id' => 'opt_doneoption1', 'value' => 'done', 'label' => 'Done'],
                ]],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_homepage01', 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
            'blocks' => [
                ['id' => 'blk_breadcrumb1', 'type' => 'breadcrumb', 'items' => [['label' => 'Home', 'href' => '/r/newblocks/home'], ['label' => 'Items']]],
                ['id' => 'blk_alertblock1', 'type' => 'alert', 'variant' => 'warning', 'title' => 'Heads up', 'body' => 'Read this.', 'icon' => 'alert-triangle', 'dismissible' => true],
                ['id' => 'blk_avatarblock', 'type' => 'avatar', 'name' => 'Ana López', 'label' => 'Ana López', 'caption' => 'Owner', 'size' => 'lg'],
                ['id' => 'blk_badgeblock1', 'type' => 'badge', 'label' => 'Activo', 'variant' => 'success', 'icon' => 'check'],
                ['id' => 'blk_stepperblk', 'type' => 'stepper', 'current_step' => 1, 'steps' => [
                    ['label' => 'Cart'], ['label' => 'Payment'], ['label' => 'Done'],
                ]],
                ['id' => 'blk_carouselbk', 'type' => 'carousel', 'autoplay' => true, 'interval_ms' => 4000, 'items' => [
                    ['image' => 'https://picsum.photos/seed/a/1200/600', 'title' => 'One'],
                    ['image' => 'https://picsum.photos/seed/b/1200/600', 'title' => 'Two'],
                ]],
                ['id' => 'blk_tableblock', 'type' => 'table', 'data_source' => ['object_id' => 'obj_itemsobject', 'limit' => 200],
                    'pagination' => ['page_size' => 25],
                    'columns' => [['id' => 'col_namecol01', 'field_id' => 'fld_namefield01']]],
            ],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_adminrole01', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    $result = app(ManifestValidator::class)->validate($manifest);

    expect($result->valid)->toBeTrue(collect($result->errors)->pluck('message')->implode("\n"));
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

it('ProfileObjectTool classifies field roles and reports grounded stats', function () {
    $manifest = $this->manifestService->getActiveManifest($this->testApp->fresh());
    $objectId = $manifest['objects'][0]['id'];

    // Enrich the object with a status (single_select) and a money (currency) field.
    $estadoId = 'fld_'.strtolower((string) Str::ulid());
    $montoId = 'fld_'.strtolower((string) Str::ulid());
    $manifest['objects'][0]['fields'][] = [
        'id' => $estadoId, 'slug' => 'estado', 'name' => 'Estado', 'type' => 'single_select',
        'options' => [
            ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'vip', 'label' => 'VIP'],
            ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'normal', 'label' => 'Normal'],
        ],
    ];
    $manifest['objects'][0]['fields'][] = [
        'id' => $montoId, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN',
    ];
    $this->manifestService->createVersion($this->testApp, $manifest);

    foreach ([['vip', 100], ['vip', 300], ['normal', 200]] as [$estado, $monto]) {
        Record::create([
            'app_id' => $this->testApp->id,
            'object_definition_id' => $objectId,
            'data' => ['nombre' => 'C'.$monto, 'estado' => $estado, 'monto' => $monto],
        ]);
    }

    $tool = new ProfileObjectTool($this->testApp->fresh(), $this->manifestService, app(RecordQueryService::class));
    $result = json_decode($tool->handle(new ToolRequest(['object_id' => $objectId])), true);

    expect($result['total_records'])->toBe(3);

    $byId = collect($result['fields'])->keyBy('id');

    // The money field is a measure with real aggregates.
    $monto = $byId[$montoId];
    expect($monto['role'])->toBe('measure')
        ->and((float) $monto['sum'])->toBe(600.0)
        ->and((float) $monto['avg'])->toBe(200.0)
        ->and((float) $monto['max'])->toBe(300.0);

    // The status field is categorical with cardinality + top values.
    $estado = $byId[$estadoId];
    expect($estado['role'])->toBe('categorical')
        ->and($estado['distinct_count'])->toBe(2)
        ->and($estado['high_cardinality'])->toBeFalse()
        ->and(collect($estado['top_values'])->firstWhere('value', 'vip')['count'])->toBe(2);

    // And it recommends concrete, data-backed visualisations.
    expect($result['recommended_visualizations'])->not->toBeEmpty()
        ->and(collect($result['recommended_visualizations'])->contains(fn ($r) => str_contains($r, 'Estado')))->toBeTrue();
});

it('ProfileObjectTool errors cleanly on an unknown object', function () {
    $tool = new ProfileObjectTool($this->testApp->fresh(), $this->manifestService, app(RecordQueryService::class));

    $bad = json_decode($tool->handle(new ToolRequest(['object_id' => 'obj_'.strtolower((string) Str::ulid())])), true);
    expect($bad['error'])->toContain('Unknown object_id');
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

it('ProposeChangeTool accepts ops whose added nodes omit their ids', function () {
    $propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifestService, $this->validator);

    // No ids anywhere — the server should mint object/field/option ids.
    $result = $propose->recordProposal([
        ['op' => 'add', 'path' => '/objects/-', 'value' => [
            'slug' => 'tareas', 'name' => 'Tareas',
            'fields' => [
                ['slug' => 'titulo', 'name' => 'Título', 'type' => 'string'],
                ['slug' => 'estado', 'name' => 'Estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                ]],
            ],
        ]],
    ], 'Agregué Tareas');

    expect($result['ok'])->toBeTrue();
    $tareas = collect($propose->runningDraft()['objects'])->firstWhere('slug', 'tareas');
    expect($tareas['id'])->toStartWith('obj_');
    expect($tareas['fields'][0]['id'])->toStartWith('fld_');
    expect($tareas['fields'][1]['options'][0]['id'])->toStartWith('opt_');
});
