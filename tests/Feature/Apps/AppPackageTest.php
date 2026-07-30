<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\User;
use App\Services\Apps\AppPackage;
use App\Services\Apps\AppTemplateCatalog;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * An app as a file. The value of the format is that what it carries WORKS
 * wherever it lands, so these are mostly about what it refuses to carry.
 */
function pkgId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id, 'organization_id' => $this->org->id,
        'slug' => 'origen', 'name' => 'Origen', 'visibility' => 'organization',
    ]);

    $this->ids = [
        'obj' => pkgId('obj'),
        'fld' => pkgId('fld'),
        'pag' => pkgId('pag'),
        'blk' => pkgId('blk'),
        'col' => pkgId('col'),
        'rol' => pkgId('rol'),
    ];

    $this->manifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'origen',
        'name' => 'Origen',
        'version' => 1,
        'objects' => [[
            'id' => $this->ids['obj'], 'slug' => 'clientes', 'name' => 'Cliente',
            'primary_display_field_id' => $this->ids['fld'],
            'fields' => [['id' => $this->ids['fld'], 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']],
        ]],
        'pages' => [[
            'id' => $this->ids['pag'], 'slug' => 'clientes', 'name' => 'Clientes', 'path' => '/clientes',
            'blocks' => [[
                'id' => $this->ids['blk'], 'type' => 'table',
                'data_source' => ['object_id' => $this->ids['obj']],
                'columns' => [['id' => $this->ids['col'], 'field_id' => $this->ids['fld']]],
            ]],
        ]],
        'permissions' => ['roles' => [
            ['id' => $this->ids['rol'], 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
        ]],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, $this->manifest, $this->owner);
    $this->testApp->refresh();

    $this->packages = app(AppPackage::class);
});

it('round-trips an app, remapping every id but keeping the references', function () {
    $installed = $this->packages->import(
        $this->packages->export($this->testApp),
        $this->owner,
        'Copia',
    )['app'];

    $manifest = app(AppManifestService::class)->getActiveManifest($installed);

    // Same shape…
    expect($manifest['objects'])->toHaveCount(1)
        ->and($manifest['objects'][0]['slug'])->toBe('clientes')
        ->and($manifest['pages'][0]['blocks'])->toHaveCount(1);

    // …none of the same ids…
    $newObjectId = $manifest['objects'][0]['id'];
    expect($newObjectId)->not->toBe($this->ids['obj'])
        ->and($manifest['pages'][0]['id'])->not->toBe($this->ids['pag']);

    // …and every reference followed its target.
    expect($manifest['pages'][0]['blocks'][0]['data_source']['object_id'])->toBe($newObjectId)
        ->and($manifest['objects'][0]['primary_display_field_id'])
        ->toBe($manifest['objects'][0]['fields'][0]['id'])
        ->and($manifest['pages'][0]['blocks'][0]['columns'][0]['field_id'])
        ->toBe($manifest['objects'][0]['fields'][0]['id']);
});

it('gives two installs of the same package separate identities', function () {
    $package = $this->packages->export($this->testApp);

    $a = $this->packages->import($package, $this->owner, 'Uno')['app'];
    $b = $this->packages->import($package, $this->owner, 'Dos')['app'];

    $manifests = app(AppManifestService::class);
    expect($manifests->getActiveManifest($a)['objects'][0]['id'])
        ->not->toBe($manifests->getActiveManifest($b)['objects'][0]['id'])
        ->and($a->slug)->not->toBe($b->slug);
});

it('keeps a connected object as a plain one, and says so', function () {
    $manifest = $this->manifest;
    $manifest['objects'][0]['source'] = [
        'type' => 'connected',
        'integration_id' => 'int_deotracuenta01',
        'operations' => ['list' => ['mcp_tool' => 'get_clientes']],
    ];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);

    $package = $this->packages->export($this->testApp->refresh());

    // The fields — and the page built on them — survive; only the wire is cut.
    expect($package['manifest']['objects'][0])->not->toHaveKey('source')
        ->and($package['manifest']['objects'][0]['fields'])->toHaveCount(1)
        ->and($package['manifest']['pages'][0]['blocks'])->toHaveCount(1)
        ->and(collect($package['portability']['removed'])->implode(' '))->toContain('reconnect a source');

    expect(json_encode($package))->not->toContain('int_deotracuenta01');
});

it('drops a workflow that needs something the new account will not have', function (array $step, string $expectIn) {
    $manifest = $this->manifest;
    $manifest['workflows'] = [[
        'id' => pkgId('wfl'), 'slug' => 'automatico', 'name' => 'Automático',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => pkgId('stp'), ...$step]],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);

    $package = $this->packages->export($this->testApp->refresh());

    // Dropped whole: a workflow missing its one meaningful step would still run
    // and silently do nothing, which reads as working.
    expect($package['manifest']['workflows'])->toBe([])
        ->and(collect($package['portability']['removed'])->implode(' '))->toContain($expectIn);
})->with([
    'an agent' => [['type' => 'agent.invoke', 'agent_id' => 'agt_ajeno01', 'message' => 'hola'], 'invokes a configured agent'],
    'a connector' => [['type' => 'connector.call', 'tool_id' => 'tol_ajeno01', 'inputs' => ['q' => 'x']], 'calls a connected system'],
]);

it('keeps a workflow that depends on nothing outside the app', function () {
    $manifest = $this->manifest;
    $manifest['workflows'] = [[
        'id' => pkgId('wfl'), 'slug' => 'registrar', 'name' => 'Registrar',
        'trigger' => ['type' => 'record.created', 'object_id' => $this->ids['obj']],
        'steps' => [['id' => pkgId('stp'), 'type' => 'log', 'message' => 'Nuevo cliente']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);

    $package = $this->packages->export($this->testApp->refresh());
    expect($package['manifest']['workflows'])->toHaveCount(1);

    // And its trigger's object_id followed the object through the remap.
    $installed = $this->packages->import($package, $this->owner, 'Copia')['app'];
    $imported = app(AppManifestService::class)->getActiveManifest($installed);
    expect($imported['workflows'][0]['trigger']['object_id'])->toBe($imported['objects'][0]['id']);
});

it('never carries a chatbot binding or another org\'s brand images', function () {
    // Built by hand rather than saved first: a chatbot binding is only valid on
    // a landing surface, so this manifest could not exist on this app — which
    // is exactly the shape a hand-edited package would arrive in. Import is the
    // gate that has to hold.
    $package = $this->packages->export($this->testApp);
    $package['manifest']['settings'] = [
        'chatbot' => ['id' => 'chatbot_deotracuenta'],
        'brand' => ['name' => 'Origen', 'logo' => 'https://app.test/brand-asset/org_otra/logo.png'],
    ];

    $result = $this->packages->import($package, $this->owner, 'Sin bot');
    $settings = app(AppManifestService::class)->getActiveManifest($result['app'])['settings'] ?? [];

    expect($settings)->not->toHaveKey('chatbot')
        ->and($settings['brand'] ?? [])->not->toHaveKey('logo')
        ->and(json_encode($settings))->not->toContain('chatbot_deotracuenta')
        ->and(json_encode($settings))->not->toContain('org_otra');
});

it('scrubs a hand-edited package on the way in, not just on the way out', function () {
    // A file is editable. A foreign reference smuggled into one must not become
    // live merely because it arrived from disk.
    $package = $this->packages->export($this->testApp);
    $package['manifest']['objects'][0]['source'] = [
        'type' => 'connected',
        'integration_id' => 'int_inyectado01',
        'operations' => ['list' => ['mcp_tool' => 'x']],
    ];

    $result = $this->packages->import($package, $this->owner, 'Sospechosa');
    $manifest = app(AppManifestService::class)->getActiveManifest($result['app']);

    expect($manifest['objects'][0])->not->toHaveKey('source')
        ->and($result['notes'])->not->toBeEmpty();
});

it('refuses a file that is not one of ours, or one from a newer version', function () {
    expect(fn () => $this->packages->import(['format' => 'otra-cosa'], $this->owner))
        ->toThrow(InvalidArgumentException::class, 'not a Sapiensly app package')
        ->and(fn () => $this->packages->import(
            ['format' => AppPackage::FORMAT, 'format_version' => 99, 'manifest' => []],
            $this->owner,
        ))->toThrow(InvalidArgumentException::class, 'newer version');
});

it('duplicates an app without touching the original', function () {
    $copy = $this->packages->duplicate($this->testApp, $this->owner)['app'];

    expect($copy->id)->not->toBe($this->testApp->id)
        ->and($copy->name)->toBe('Origen (copia)')
        ->and($copy->slug)->not->toBe('origen');

    // The original's manifest is untouched — ids and all.
    expect(app(AppManifestService::class)->getActiveManifest($this->testApp->refresh())['objects'][0]['id'])
        ->toBe($this->ids['obj']);
});

it('ships starter templates that install through the same path', function () {
    $catalog = app(AppTemplateCatalog::class);
    $templates = $catalog->all();

    expect($templates)->not->toBeEmpty();

    foreach ($templates as $template) {
        $package = $catalog->package($template['slug']);
        expect($package)->not->toBeNull();

        // Installing is the real assertion: it runs the manifest validator.
        $installed = $this->packages->import($package, $this->owner)['app'];
        expect(app(AppManifestService::class)->getActiveManifest($installed))->not->toBeNull();
    }
});

it('refuses a template slug that tries to leave the directory', function () {
    $catalog = app(AppTemplateCatalog::class);

    expect($catalog->package('../../../.env'))->toBeNull()
        ->and($catalog->package('no-existe'))->toBeNull();
});
