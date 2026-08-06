<?php

use App\Ai\Tools\Builder\AddFieldTool;
use App\Ai\Tools\Builder\AddObjectTool;
use App\Ai\Tools\Builder\AddRelationTool;
use App\Ai\Tools\Builder\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestEditor;
use App\Services\Manifest\ManifestValidator;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The builder's typed data-model edits.
 *
 * `ManifestEditor` has computed these since it was written and the MCP server
 * has exposed them as add_field / add_object / add_relation — but the BUILDER,
 * which writes them dozens of times per app, only had raw propose_change, and so
 * hand-enumerated every `field_id` into every form and table. Measured over four
 * runs of one brief, that enumeration was the largest class of rejected patch in
 * the ledger: one invented id sprayed across twelve pointers in a single call.
 *
 * Every assertion below is about the same property: the caller names things by
 * SLUG, and there is no id for it to get wrong.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'campo_ops',
    ]);
    $this->manifests = app(AppManifestService::class);

    $manifest = app(AppScaffolder::class)->assemble($this->manifests->initialManifest($this->testApp), [
        'objects' => [
            ['name' => 'Ordenes', 'slug' => 'ordenes', 'fields' => [
                ['name' => 'Numero', 'slug' => 'numero', 'type' => 'string', 'options' => null],
            ]],
            ['name' => 'Clientes', 'slug' => 'clientes', 'fields' => [
                ['name' => 'Nombre', 'slug' => 'nombre', 'type' => 'string', 'options' => null],
            ]],
        ],
        'pages' => [
            ['object' => 'ordenes'],
            ['object' => 'clientes'],
        ],
    ]);
    $this->manifests->createVersion($this->testApp, $manifest, $this->user, 'seed');

    $this->propose = new ProposeChangeTool($this->testApp->fresh(), $this->manifests, app(ManifestValidator::class));
    $this->editor = app(ManifestEditor::class);
});

/** Every field id referenced anywhere under a node. */
function referencedFieldIds(mixed $node, array &$found = []): array
{
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            if ($key === 'field_id' && is_string($value)) {
                $found[$value] = true;
            } elseif (is_array($value)) {
                referencedFieldIds($value, $found);
            }
        }
    }

    return array_keys($found);
}

it('add_field names the object by slug and wires the field in by itself', function () {
    $tool = new AddFieldTool($this->propose, $this->editor);

    $out = json_decode($tool->handle(new ToolRequest([
        'object_slug' => 'ordenes',
        'name' => 'Firma del cliente',
        'type' => 'file',
        'config' => ['capture' => 'signature'],
    ])), true);

    expect($out['ok'])->toBeTrue();

    $draft = $this->propose->runningDraft();
    $ordenes = collect($draft['objects'])->firstWhere('slug', 'ordenes');
    $field = collect($ordenes['fields'])->firstWhere('slug', 'firma_del_cliente');

    // The field exists, carries its capture, and — the part the model used to
    // hand-write — is referenced from the page blocks it belongs on.
    expect($field)->not->toBeNull()
        ->and($field['type'])->toBe('file')
        ->and($field['capture'])->toBe('signature')
        ->and(referencedFieldIds($draft['pages']))->toContain($field['id']);
});

it('add_field keeps a computed field out of the create form', function () {
    $tool = new AddFieldTool($this->propose, $this->editor);

    $out = json_decode($tool->handle(new ToolRequest([
        'object_slug' => 'ordenes',
        'name' => 'Total',
        'type' => 'formula',
        'config' => ['expression' => '{{1 + 1}}', 'return_type' => 'number'],
    ])), true);

    expect($out['ok'])->toBeTrue();

    $draft = $this->propose->runningDraft();
    $total = collect(collect($draft['objects'])->firstWhere('slug', 'ordenes')['fields'])
        ->firstWhere('slug', 'total');

    expect($total['readonly'])->toBeTrue();

    // Present somewhere (a table), but never in a form: a create form that
    // submitted a computed value would be writing a column nobody can write.
    $formFieldIds = [];
    foreach ($draft['pages'] as $page) {
        foreach ($page['blocks'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'form') {
                referencedFieldIds($block, $formFieldIds);
            }
        }
    }
    expect(array_keys($formFieldIds))->not->toContain($total['id']);
});

it('add_relation links two slugs and builds both sides', function () {
    $tool = new AddRelationTool($this->propose, $this->editor);

    $out = json_decode($tool->handle(new ToolRequest([
        'from_slug' => 'ordenes',
        'to_slug' => 'clientes',
    ])), true);

    expect($out['ok'])->toBeTrue();

    $draft = $this->propose->runningDraft();
    $ordenes = collect($draft['objects'])->firstWhere('slug', 'ordenes');
    $clientes = collect($draft['objects'])->firstWhere('slug', 'clientes');

    $child = collect($ordenes['fields'])->firstWhere('type', 'relation');
    $parent = collect($clientes['fields'])->firstWhere('type', 'relation');

    // Both directions, pointing at each other — the pair the model used to have
    // to write by hand, inverse_field_id included.
    expect($child)->not->toBeNull()
        ->and($child['target_object_id'])->toBe($clientes['id'])
        ->and($parent)->not->toBeNull()
        ->and($parent['target_object_id'])->toBe($ordenes['id'])
        ->and($child['inverse_field_id'])->toBe($parent['id']);
});

it('add_object adds the object, its policy row and its page', function () {
    $tool = new AddObjectTool($this->propose, $this->editor);

    $out = json_decode($tool->handle(new ToolRequest([
        'name' => 'Refacciones',
        'fields' => [
            ['name' => 'SKU', 'type' => 'string', 'config' => ['capture' => 'barcode']],
            ['name' => 'Precio', 'type' => 'currency'],
        ],
    ])), true);

    expect($out['ok'])->toBeTrue()
        ->and($out['object']['slug'])->toBe('refacciones');

    $draft = $this->propose->runningDraft();
    $object = collect($draft['objects'])->firstWhere('slug', 'refacciones');
    $sku = collect($object['fields'])->firstWhere('slug', 'sku');

    expect($sku)->not->toBeNull()
        // A page to use it from, and a policy row so the access rules keep
        // covering every object rather than quietly stopping at the last one.
        ->and(collect($draft['pages'])->pluck('slug'))->toContain('refacciones')
        ->and(collect($draft['permissions']['object_policies'] ?? [])->pluck('object_id'))
        ->toContain($object['id']);

    // Creating an object is the BASIC type path, so `capture` is NOT applied
    // here — and that is said out loud rather than dropped in silence. Every
    // run of one field-service brief ended with the critic reporting a plain
    // `sku` where a scannable one was asked for; this is where that starts.
    expect($sku)->not->toHaveKey('capture')
        ->and(implode(' ', $out['coercions'] ?? []))->toContain('add_field');
});

it('add_field then sets the capture that creating the object could not', function () {
    $add = new AddObjectTool($this->propose, $this->editor);
    json_decode($add->handle(new ToolRequest([
        'name' => 'Refacciones',
        'fields' => [['name' => 'SKU', 'type' => 'string']],
    ])), true);

    $out = json_decode((new AddFieldTool($this->propose, $this->editor))->handle(new ToolRequest([
        'object_slug' => 'refacciones',
        'name' => 'Codigo de barras',
        'type' => 'string',
        'config' => ['capture' => 'barcode'],
    ])), true);

    expect($out['ok'])->toBeTrue();

    $field = collect(collect($this->propose->runningDraft()['objects'])->firstWhere('slug', 'refacciones')['fields'])
        ->firstWhere('slug', 'codigo_de_barras');

    expect($field['capture'])->toBe('barcode');
});

it('refuses an object slug that does not exist, and says which ones do', function () {
    $tool = new AddFieldTool($this->propose, $this->editor);

    $out = json_decode($tool->handle(new ToolRequest([
        'object_slug' => 'refaciones',
        'name' => 'SKU',
    ])), true);

    expect($out['ok'])->toBeFalse()
        ->and($out['errors'][0]['message'])->toContain('refaciones')
        // The real slugs, so the retry is a lookup rather than a guess.
        ->and($out['errors'][0]['message'])->toContain('ordenes')
        ->and($out['errors'][0]['message'])->toContain('clientes');
});

it('stacks onto the running draft rather than writing its own version', function () {
    // The builder's unit of work is the TURN. A tool that persisted on its own
    // would split one turn across two versions and break undo.
    $before = $this->testApp->fresh()->currentVersion->version_number;

    $out = json_decode((new AddFieldTool($this->propose, $this->editor))->handle(new ToolRequest([
        'object_slug' => 'clientes',
        'name' => 'Telefono',
        'type' => 'phone',
    ])), true);

    expect($out['ok'])->toBeTrue()
        ->and($this->testApp->fresh()->currentVersion->version_number)->toBe($before)
        // It is in the draft, waiting for the turn to end.
        ->and(collect(collect($this->propose->runningDraft()['objects'])->firstWhere('slug', 'clientes')['fields'])
            ->pluck('slug'))->toContain('telefono');
});
