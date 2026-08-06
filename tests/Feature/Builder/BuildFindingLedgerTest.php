<?php

use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\BuildFinding;
use App\Models\User;
use App\Services\Builder\BuildFindingLedger;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * `ai_usage_events` records what a build COST. Nothing recorded whether it came
 * out right, so a recurring defect could only be noticed by someone reading two
 * conversations by hand — which is how the last round of rails got written, off
 * a sample of two. This ledger keeps the three failure signals the builder
 * already produces and already throws away.
 */
function ledgerId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

/**
 * A manifest that is CLEAN — it validates with no warnings of its own — so a
 * finding in any test below came from that test's patch and nothing else. The
 * single page is named nothing like the object, because the mistake being
 * modelled is a patch aimed by name and addressed by index.
 */
function ledgerManifest(string $appId): array
{
    $objectId = ledgerId('obj');
    $fieldId = ledgerId('fld');

    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'servicio',
        'name' => 'Servicio',
        'version' => 1,
        // The design-smell rules read field names through the locale's
        // vocabulary; without this they resolve to English and 'firma' means
        // nothing to them.
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [[
            'id' => $objectId,
            'slug' => 'ordenes',
            'name' => 'Órdenes',
            'fields' => [
                ['id' => $fieldId, 'slug' => 'numero', 'name' => 'Número', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => ledgerId('pag'),
            'slug' => 'refacciones_detail',
            'name' => 'Refacción',
            'path' => '/refacciones_detail',
            'blocks' => [
                ['id' => ledgerId('blk'), 'type' => 'heading', 'content' => 'Refacción', 'level' => 1],
                // Real content, and a reader for the object: without both, the
                // baseline manifest warns on its own.
                [
                    'id' => ledgerId('blk'),
                    'type' => 'table',
                    'data_source' => ['object_id' => $objectId],
                    'columns' => [['id' => ledgerId('col'), 'field_id' => $fieldId]],
                ],
            ],
        ]],
        'permissions' => [
            'roles' => [['id' => ledgerId('rol'), 'slug' => 'admin', 'name' => 'Admin']],
        ],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    app(AppManifestService::class)->createVersion(
        $this->testApp,
        ledgerManifest($this->testApp->id),
        $this->user,
    );
    $this->propose = new ProposeChangeTool(
        $this->testApp->fresh(),
        app(AppManifestService::class),
        app(ManifestValidator::class),
    );
    $this->propose->attribute('cnv_ledger_test', 'anthropic/claude-haiku-latest');
});

/** A patch the validator will refuse: a heading with no content. */
function rejectedPatch(ProposeChangeTool $tool, string $summary = 'Add a heading'): array
{
    return json_decode($tool->handle(new ToolRequest([
        'ops' => [[
            'op' => 'add',
            'path' => '/pages/0/blocks/-',
            'value' => ['id' => ledgerId('blk'), 'type' => 'heading'],
        ]],
        'change_summary' => $summary,
    ])), true);
}

it('keeps a rejected patch, with the code and the place it landed', function () {
    expect(rejectedPatch($this->propose)['ok'])->toBeFalse();

    $finding = BuildFinding::where('app_id', $this->testApp->id)->first();

    expect($finding)->not->toBeNull()
        ->and($finding->signal)->toBe(BuildFinding::SIGNAL_PATCH_REJECTED)
        ->and($finding->code)->not->toBeNull()
        ->and($finding->path)->toStartWith('/pages/0')
        // The human location propose_change attaches — the fact that explains a
        // mis-indexed patch, and the reason this column exists at all.
        ->and($finding->at)->toContain('refacciones_detail')
        ->and($finding->conversation_id)->toBe('cnv_ledger_test')
        ->and($finding->model)->toBe('anthropic/claude-haiku-latest');
});

it('counts the same mistake twice when the model makes it twice', function () {
    // Not deduplicated on purpose: a patch refused, corrected wrongly and
    // refused again is two attempts, and the repetition is the pattern worth
    // seeing. Only WARNINGS get folded (see the next test).
    rejectedPatch($this->propose, 'first try');
    rejectedPatch($this->propose, 'second try');

    expect(BuildFinding::where('signal', BuildFinding::SIGNAL_PATCH_REJECTED)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('records a design warning once per turn, not once per call', function () {
    $add = fn (string $slug, string $name) => json_decode($this->propose->handle(new ToolRequest([
        'ops' => [[
            'op' => 'add',
            'path' => '/objects/0/fields/-',
            'value' => ['id' => ledgerId('fld'), 'slug' => $slug, 'name' => $name, 'type' => 'string'],
        ]],
        'change_summary' => 'Add '.$slug,
    ])), true);

    // A signature held as plain text: applies fine, but the validator warns.
    $first = $add('firma', 'Firma del cliente');
    expect($first['ok'])->toBeTrue()
        ->and($first['warnings'] ?? [])->not->toBeEmpty();

    $smells = fn (): int => BuildFinding::where('signal', BuildFinding::SIGNAL_DESIGN_SMELL)->count();
    $afterFirst = $smells();
    expect($afterFirst)->toBeGreaterThan(0);

    // Warnings are recomputed over the WHOLE draft on every call, so the same
    // unfixed smell rides along on this innocuous second patch. Counted once
    // per call it would swamp the rankings with one build's single mistake.
    $second = $add('estado', 'Estado');
    expect($second['warnings'] ?? [])->not->toBeEmpty();

    expect($smells())->toBe($afterFirst);
});

it('writes nothing when a patch is accepted cleanly', function () {
    $result = json_decode($this->propose->handle(new ToolRequest([
        'ops' => [[
            'op' => 'add',
            'path' => '/objects/0/fields/-',
            'value' => ['id' => ledgerId('fld'), 'slug' => 'estado', 'name' => 'Estado', 'type' => 'string'],
        ]],
        'change_summary' => 'Add a status field',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['warnings'] ?? [])->toBeEmpty()
        ->and(BuildFinding::count())->toBe(0);
});

it('caps one batch so a hundred identical errors cannot flood the table', function () {
    $written = app(BuildFindingLedger::class)->record(
        $this->testApp->id,
        BuildFinding::SIGNAL_PATCH_REJECTED,
        array_map(fn (int $i): array => ['code' => 'bad', 'detail' => "error {$i}"], range(1, 100)),
    );

    expect($written)->toBe(25)
        ->and(BuildFinding::count())->toBe(25);
});

it('skips a finding with nothing to say', function () {
    $written = app(BuildFindingLedger::class)->record(
        $this->testApp->id,
        BuildFinding::SIGNAL_CRITIC,
        [['code' => 'missing', 'detail' => '  '], ['code' => 'missing', 'detail' => 'la firma es un campo de texto']],
    );

    expect($written)->toBe(1);
});

it('ranks recurring patterns and normalises a model by the builds behind it', function () {
    $ledger = app(BuildFindingLedger::class);
    $model = 'anthropic/claude-haiku-latest';

    // Two builds on one model: three rejections of one kind, one of another.
    $ledger->record($this->testApp->id, BuildFinding::SIGNAL_PATCH_REJECTED, [
        ['code' => 'unknown_field_type', 'detail' => 'a'],
        ['code' => 'unknown_field_type', 'detail' => 'b'],
    ], 'cnv_one', $model);
    $ledger->record($this->testApp->id, BuildFinding::SIGNAL_PATCH_REJECTED, [
        ['code' => 'unknown_field_type', 'detail' => 'c'],
        ['code' => 'missing_content', 'detail' => 'd'],
    ], 'cnv_two', $model);
    $ledger->record($this->testApp->id, BuildFinding::SIGNAL_CRITIC, [
        ['code' => BuildFinding::CODE_UNREQUESTED, 'detail' => 'una página «Punto de venta»'],
    ], 'cnv_two', $model);

    $report = $ledger->report($this->testApp->id);

    expect($report['totals']['findings'])->toBe(5)
        ->and($report['totals']['by_signal'][BuildFinding::SIGNAL_PATCH_REJECTED])->toBe(4)
        ->and($report['totals']['by_signal'][BuildFinding::SIGNAL_CRITIC])->toBe(1)
        // The ranking is the point: this is what becomes a deterministic rule.
        ->and($report['top_codes'][0]['code'])->toBe('unknown_field_type')
        ->and($report['top_codes'][0]['count'])->toBe(3);

    $byModel = collect($report['by_model'])->firstWhere('model', $model);

    // 5 findings over 2 builds — the per-build figure is what makes two models
    // comparable when one of them has run far more builds than the other.
    expect($byModel['builds'])->toBe(2)
        ->and($byModel['per_build'])->toBe(2.5);
});

it('leaves another app out of an app-scoped report', function () {
    $other = App::factory()->create();
    $ledger = app(BuildFindingLedger::class);

    $ledger->record($this->testApp->id, BuildFinding::SIGNAL_CRITIC, [['code' => 'missing', 'detail' => 'mine']]);
    $ledger->record($other->id, BuildFinding::SIGNAL_CRITIC, [['code' => 'missing', 'detail' => 'theirs']]);

    expect($ledger->report($this->testApp->id)['totals']['findings'])->toBe(1)
        // Omitting the app is the org-wide view — the one that surfaces
        // patterns no single build is big enough to show.
        ->and($ledger->report()['totals']['findings'])->toBe(2);
});
