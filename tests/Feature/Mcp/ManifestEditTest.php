<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\AddFieldTool;
use App\Mcp\Tools\Build\AddObjectTool;
use App\Mcp\Tools\Build\AddRelationTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ManifestValidator;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->appModel), ['objects' => [
        ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
            ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
        ]],
    ]]);
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
});

function currentManifest(App $app): array
{
    $app->refresh();

    return app(AppManifestService::class)->getActiveManifest($app);
}

/** Find the first block of a type anywhere in a page's block tree. */
function findBlock(array $blocks, string $type): ?array
{
    foreach ($blocks as $block) {
        if (($block['type'] ?? null) === $type) {
            return $block;
        }
        if (isset($block['blocks']) && ($found = findBlock($block['blocks'], $type)) !== null) {
            return $found;
        }
    }

    return null;
}

it('add_field adds the field and wires it into the table and create form', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'ideas',
            'name' => 'Status',
            'type' => 'single_select',
            'options' => [
                ['value' => 'backlog', 'label' => 'Backlog'],
                ['value' => 'ready', 'label' => 'Ready'],
            ],
        ])
        ->assertOk()
        ->assertSee('version_number');

    $manifest = currentManifest($this->appModel);

    expect($manifest['objects'][0]['fields'])->toHaveCount(2);
    $newField = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'status');
    expect($newField['type'])->toBe('single_select');

    $page = collect($manifest['pages'])->firstWhere('path', '/ideas');
    $table = findBlock($page['blocks'], 'table');
    $form = findBlock($page['blocks'], 'form');

    // The new field is a table column and a form field, and the table keeps
    // the trailing "Created" (sys_created_at) column last.
    expect(collect($table['columns'])->pluck('field_id'))->toContain($newField['id']);
    expect(collect($table['columns'])->last()['field_id'])->toBe('sys_created_at');
    expect(collect($form['fields'])->pluck('field_id'))->toContain($newField['id']);

    $createValues = collect($form['on_submit'])->firstWhere('type', 'create_record')['values'];
    expect($createValues)->toHaveKey('status', '{{form.status}}');

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('add_object adds a new object with its own CRUD page', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddObjectTool::class, [
            'app_slug' => 'content_engine',
            'name' => 'Drafts',
            'fields' => [
                ['name' => 'Title', 'type' => 'string'],
                ['name' => 'Budget', 'type' => 'currency'],
            ],
        ])
        ->assertOk();

    $manifest = currentManifest($this->appModel);

    expect($manifest['objects'])->toHaveCount(2);
    // Seeded dashboard + ideas page, plus the new drafts page.
    expect($manifest['pages'])->toHaveCount(3);
    expect(collect($manifest['objects'])->pluck('slug'))->toContain('drafts');
    expect(collect($manifest['pages'])->pluck('path'))->toContain('/drafts');
    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('add_relation links two objects belongs-to and wires the picker into the page', function () {
    // Seed a second object to link to.
    SapiensServer::actingAs($this->user)
        ->tool(AddObjectTool::class, [
            'app_slug' => 'content_engine',
            'name' => 'Drafts',
            'fields' => [['name' => 'Title', 'type' => 'string']],
        ])
        ->assertOk();

    SapiensServer::actingAs($this->user)
        ->tool(AddRelationTool::class, [
            'app_slug' => 'content_engine',
            'from_object' => 'drafts',
            'to_object' => 'ideas',
            'name' => 'Idea',
        ])
        ->assertOk();

    $manifest = currentManifest($this->appModel);
    $ideas = collect($manifest['objects'])->firstWhere('slug', 'ideas');
    $drafts = collect($manifest['objects'])->firstWhere('slug', 'drafts');

    $belongsTo = collect($drafts['fields'])->firstWhere('type', 'relation');
    $hasMany = collect($ideas['fields'])->firstWhere('type', 'relation');
    expect($belongsTo['cardinality'])->toBe('many_to_one');
    expect($belongsTo['target_object_id'])->toBe($ideas['id']);
    expect($hasMany['cardinality'])->toBe('one_to_many');
    expect($belongsTo['inverse_field_id'])->toBe($hasMany['id']);

    // Picker on the Drafts create form.
    $draftsPage = collect($manifest['pages'])->firstWhere('path', '/drafts');
    $form = findBlock($draftsPage['blocks'], 'form');
    expect(collect($form['fields'])->pluck('field_id'))->toContain($belongsTo['id']);

    // Ideas gains a child-count rollup, shown on its table only.
    $rollup = collect($ideas['fields'])->firstWhere('type', 'rollup');
    expect($rollup['aggregator'])->toBe('count');
    $ideasPage = collect($manifest['pages'])->firstWhere('path', '/ideas');
    $ideasTable = findBlock($ideasPage['blocks'], 'table');
    $ideasForm = findBlock($ideasPage['blocks'], 'form');
    expect(collect($ideasTable['columns'])->pluck('field_id'))->toContain($rollup['id']);
    expect(collect($ideasForm['fields'])->pluck('field_id'))->not->toContain($rollup['id']);

    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('add_relation kind=many_to_many puts a symmetric picker on both objects', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddObjectTool::class, [
            'app_slug' => 'content_engine',
            'name' => 'Tags',
            'fields' => [['name' => 'Label', 'type' => 'string']],
        ])
        ->assertOk();

    SapiensServer::actingAs($this->user)
        ->tool(AddRelationTool::class, [
            'app_slug' => 'content_engine',
            'from_object' => 'ideas',
            'to_object' => 'tags',
            'kind' => 'many_to_many',
        ])
        ->assertOk()
        ->assertSee('many-to-many');

    $manifest = currentManifest($this->appModel);
    $ideas = collect($manifest['objects'])->firstWhere('slug', 'ideas');
    $tags = collect($manifest['objects'])->firstWhere('slug', 'tags');

    $ideasM2M = collect($ideas['fields'])->first(fn ($f) => ($f['type'] ?? '') === 'relation' && ($f['cardinality'] ?? '') === 'many_to_many');
    $tagsM2M = collect($tags['fields'])->first(fn ($f) => ($f['type'] ?? '') === 'relation' && ($f['cardinality'] ?? '') === 'many_to_many');

    expect($ideasM2M)->not->toBeNull()
        ->and($tagsM2M)->not->toBeNull()
        ->and($ideasM2M['target_object_id'])->toBe($tags['id'])
        ->and($tagsM2M['target_object_id'])->toBe($ideas['id'])
        ->and($ideasM2M['inverse_field_id'])->toBe($tagsM2M['id'])
        ->and($tagsM2M['inverse_field_id'])->toBe($ideasM2M['id']);

    // No child-count rollup for a many-to-many (that is a belongs-to affordance).
    expect(collect($tags['fields'])->where('type', 'rollup'))->toBeEmpty();
    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('add_relation rejects an unknown object', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddRelationTool::class, [
            'app_slug' => 'content_engine',
            'from_object' => 'ideas',
            'to_object' => 'nope',
        ])
        ->assertHasErrors();
});

it('add_field rejects an unknown object', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'does_not_exist',
            'name' => 'X',
        ])
        ->assertHasErrors();
});

it('add_field degrades a select with no options to plain text', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'ideas',
            'name' => 'Tag',
            'type' => 'single_select',
            'options' => [],
        ])
        ->assertOk();

    $manifest = currentManifest($this->appModel);
    $field = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'tag');
    expect($field['type'])->toBe('string');
    expect(app(ManifestValidator::class)->validate($manifest)->valid)->toBeTrue();
});

it('add_field supports advanced scalar types like slider via config', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'ideas',
            'name' => 'Confidence',
            'type' => 'slider',
            'config' => ['min' => 0, 'max' => 100, 'step' => 5],
        ])
        ->assertOk()
        ->assertSee('version_number');

    $field = collect(currentManifest($this->appModel)['objects'][0]['fields'])->firstWhere('slug', 'confidence');

    expect($field['type'])->toBe('slider')
        ->and($field['min'])->toBe(0)
        ->and($field['max'])->toBe(100)
        ->and($field['step'])->toBe(5);
});

it('add_field builds a computed field as read-only and keeps it out of the create form', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'ideas',
            'name' => 'Title Length',
            'type' => 'formula',
            'config' => ['expression' => 'length({{title}})', 'return_type' => 'number'],
        ])
        ->assertOk()
        ->assertSee('version_number');

    $manifest = currentManifest($this->appModel);
    $field = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'title_length');

    expect($field['type'])->toBe('formula')
        ->and($field['readonly'])->toBeTrue()
        ->and($field['expression'])->toBe('length({{title}})');

    // Computed: present as a table column, but never wired into the create form.
    $page = collect($manifest['pages'])->first(fn ($p) => findBlock($p['blocks'] ?? [], 'form') !== null);
    $form = findBlock($page['blocks'], 'form');
    expect(collect($form['fields'] ?? [])->pluck('field_id'))->not->toContain($field['id']);
});

it('add_field carries common base props from config', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'ideas',
            'name' => 'External Ref',
            'type' => 'string',
            'config' => ['required' => true, 'unique' => true, 'help_text' => 'From the source system'],
        ])
        ->assertOk();

    $field = collect(currentManifest($this->appModel)['objects'][0]['fields'])->firstWhere('slug', 'external_ref');

    expect($field['required'])->toBeTrue()
        ->and($field['unique'])->toBeTrue()
        ->and($field['help_text'])->toBe('From the source system');
});

it('add_field reports a coercion as a warning instead of silently dropping intent', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddFieldTool::class, [
            'app_slug' => 'content_engine',
            'object_slug' => 'ideas',
            'name' => 'Stage',
            'type' => 'single_select', // no options provided → degraded to plain text
        ])
        ->assertOk()
        ->assertSee('warnings')
        ->assertSee('plain text');

    // It still landed as a usable string field.
    $field = collect(currentManifest($this->appModel)['objects'][0]['fields'])->firstWhere('slug', 'stage');
    expect($field['type'])->toBe('string');
});
