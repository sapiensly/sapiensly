<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\AddFieldTool;
use App\Mcp\Tools\Build\AddObjectTool;
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
