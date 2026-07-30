<?php

use App\Models\App;
use App\Models\Organization;
use App\Models\Record;
use App\Models\User;
use App\Services\Import\ImportPlan;
use App\Services\Import\ImportService;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

/**
 * The import end to end, against the database. The point of these is that rows
 * go through the SAME validated write path a typed form uses — so whatever the
 * app would refuse from a person, it refuses from a file.
 */
function importId(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);

    $this->testApp = App::create([
        'user_id' => $this->owner->id,
        'organization_id' => $this->org->id,
        'slug' => 'ventas',
        'name' => 'Ventas',
        'visibility' => 'organization',
    ]);

    $this->baseManifest = [
        'schema_version' => '1.0.0',
        'id' => $this->testApp->id,
        'slug' => 'ventas',
        'name' => 'Ventas',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [
            ['id' => importId('rol'), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
        ]],
    ];

    app(AppManifestService::class)->createVersion($this->testApp, $this->baseManifest, $this->owner);
    $this->testApp->refresh();

    $this->service = app(ImportService::class);
});

it('builds a working object out of a file and imports the rows', function () {
    $csv = <<<'CSV'
    Nombre;Correo;Precio;Fecha de alta;Estado;Activo
    Acme;ana@acme.com;1.200,50;25/12/2026;activo;sí
    Globex;luis@globex.io;900,00;03/01/2026;pausado;no
    Initech;sara@initech.com;15.000,00;14/02/2026;activo;sí
    CSV;

    $sheet = $this->service->readString($csv);
    $plan = $this->service->plan($this->testApp, $sheet, objectName: 'Clientes');

    // Each column landed on the type its VALUES imply, not on string.
    $types = collect($plan->mappings)->pluck('type', 'header')->all();
    expect($types)->toBe([
        'Nombre' => 'string',
        'Correo' => 'email',
        'Precio' => 'currency',
        'Fecha de alta' => 'date',
        'Estado' => 'string', // only 3 rows — too few to call it a choice list
        'Activo' => 'boolean',
    ]);

    // Headers became valid manifest slugs, accents folded.
    expect(collect($plan->object['fields'])->pluck('slug')->all())
        ->toBe(['nombre', 'correo', 'precio', 'fecha_de_alta', 'estado', 'activo']);

    $result = $this->service->run($this->testApp, $sheet, $plan, $this->owner);

    expect($result->created)->toBe(3)
        ->and($result->failed)->toBe(0);

    $rows = Record::where('app_id', $this->testApp->id)->get();
    expect($rows)->toHaveCount(3);

    $acme = $rows->firstWhere(fn (Record $r): bool => $r->data['nombre'] === 'Acme');
    // The Spanish decimal comma survived as a real number, not 1.2 or a string.
    expect($acme->data['precio'])->toBe(1200.5)
        ->and($acme->data['fecha_de_alta'])->toBe('2026-12-25')
        ->and($acme->data['activo'])->toBeTrue()
        ->and($acme->data['correo'])->toBe('ana@acme.com');
});

it('imports into an object that already exists, matching columns by name', function () {
    $objId = importId('obj');
    $manifest = $this->baseManifest;
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [
            ['id' => importId('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ['id' => importId('fld'), 'slug' => 'correo_electronico', 'name' => 'Correo Electrónico', 'type' => 'email'],
        ],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);
    $this->testApp->refresh();

    // «Correo Electrónico» in the file must find `correo_electronico` — accents
    // folded, spaces ignored. «Teléfono» matches nothing and is reported.
    $sheet = $this->service->readString("Nombre,Correo Electrónico,Teléfono\nAna,ana@acme.com,+52 55 1234 5678\n");
    $plan = $this->service->plan($this->testApp, $sheet, objectSlug: 'clientes');

    $bySlug = collect($plan->mappings)->pluck('fieldSlug', 'header')->all();
    expect($bySlug)->toBe([
        'Nombre' => 'nombre',
        'Correo Electrónico' => 'correo_electronico',
        'Teléfono' => null,
    ]);

    $skipped = collect($plan->mappings)->firstWhere('header', 'Teléfono');
    expect($skipped->skipReason)->toContain('No field');

    $result = $this->service->run($this->testApp, $sheet, $plan, $this->owner);

    expect($result->created)->toBe(1);
    expect(Record::where('object_definition_id', $objId)->first()->data)
        ->toBe(['nombre' => 'Ana', 'correo_electronico' => 'ana@acme.com']);
});

it('updates instead of duplicating when a matching column is chosen', function () {
    $objId = importId('obj');
    $manifest = $this->baseManifest;
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [
            ['id' => importId('fld'), 'slug' => 'codigo', 'name' => 'Codigo', 'type' => 'string'],
            ['id' => importId('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
        ],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);
    $this->testApp->refresh();

    $first = $this->service->readString("Codigo,Nombre\nC-1,Acme\nC-2,Globex\n");
    $plan = $this->service->plan($this->testApp, $first, objectSlug: 'clientes', upsertKeyHeader: 'Codigo');
    expect($plan->upsertKey)->toBe('codigo');
    $this->service->run($this->testApp, $first, $plan, $this->owner);

    // Same codes, one renamed, one new.
    $second = $this->service->readString("Codigo,Nombre\nC-1,Acme Corp\nC-3,Initech\n");
    $plan2 = $this->service->plan($this->testApp, $second, objectSlug: 'clientes', upsertKeyHeader: 'Codigo');
    $result = $this->service->run($this->testApp, $second, $plan2, $this->owner);

    expect($result->updated)->toBe(1)
        ->and($result->created)->toBe(1);

    $rows = Record::where('object_definition_id', $objId)->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->firstWhere(fn (Record $r): bool => $r->data['codigo'] === 'C-1')->data['nombre'])
        ->toBe('Acme Corp');
});

it('reports a bad row by its line number and imports the rest', function () {
    $objId = importId('obj');
    $manifest = $this->baseManifest;
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [
            ['id' => importId('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string', 'required' => true],
            ['id' => importId('fld'), 'slug' => 'correo', 'name' => 'Correo', 'type' => 'email'],
        ],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);
    $this->testApp->refresh();

    // Row 3 carries something the app itself would refuse from a form.
    $sheet = $this->service->readString("Nombre,Correo\nAna,ana@acme.com\nLuis,no-es-un-correo\nSara,sara@acme.com\n");
    $plan = $this->service->plan($this->testApp, $sheet, objectSlug: 'clientes');
    $result = $this->service->run($this->testApp, $sheet, $plan, $this->owner);

    expect($result->created)->toBe(2)
        ->and($result->failed)->toBe(1)
        // The line number is the one the user sees in their spreadsheet.
        ->and($result->errors[0]['row'])->toBe(3)
        ->and($result->errors[0]['errors'])->toHaveKey('correo')
        ->and($result->summary())->toContain('1 failed');

    expect(Record::where('object_definition_id', $objId)->count())->toBe(2);
});

it('refuses to write a derived field, and does not even offer it', function () {
    $objId = importId('obj');
    $fldPrecio = importId('fld');
    $manifest = $this->baseManifest;
    $manifest['objects'] = [[
        'id' => $objId, 'slug' => 'items', 'name' => 'Item',
        'fields' => [
            ['id' => $fldPrecio, 'slug' => 'precio', 'name' => 'Precio', 'type' => 'number'],
            [
                'id' => importId('fld'), 'slug' => 'con_iva', 'name' => 'Con IVA',
                'type' => 'formula', 'readonly' => true,
                'expression' => 'precio * 1.16', 'return_type' => 'number',
            ],
        ],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);
    $this->testApp->refresh();

    $sheet = $this->service->readString("Precio,Con IVA\n100,116\n");
    $plan = $this->service->plan($this->testApp, $sheet, objectSlug: 'items');

    // A formula is computed at query time; writing to it is meaningless, so it
    // is never presented as a target in the first place.
    expect(collect($plan->object['fields'])->pluck('slug')->all())->toBe(['precio'])
        ->and(collect($plan->mappings)->firstWhere('header', 'Con IVA')->fieldSlug)->toBeNull();

    $result = $this->service->run($this->testApp, $sheet, $plan, $this->owner);
    $stored = Record::where('object_definition_id', $objId)->first()->data;

    // The 116 in the file was NOT written: a formula is computed on read, and a
    // stale value from a spreadsheet would silently shadow the real one.
    expect($result->created)->toBe(1)
        ->and($stored['precio'])->toEqual(100)
        ->and($stored['con_iva'])->toBeNull();
});

it('warns instead of importing when no column matches the target object', function () {
    $manifest = $this->baseManifest;
    $manifest['objects'] = [[
        'id' => importId('obj'), 'slug' => 'clientes', 'name' => 'Cliente',
        'fields' => [['id' => importId('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string']],
    ]];
    app(AppManifestService::class)->createVersion($this->testApp, $manifest, $this->owner);
    $this->testApp->refresh();

    $sheet = $this->service->readString("Producto,SKU\nCafé,C-1\n");
    $plan = $this->service->plan($this->testApp, $sheet, objectSlug: 'clientes');

    expect($plan->mapped())->toBeEmpty()
        ->and(collect($plan->warnings)->implode(' '))->toContain('nothing would be imported');

    $result = $this->service->run($this->testApp, $sheet, $plan, $this->owner);
    expect($result->created)->toBe(0);
});

it('refuses a plan against an object that does not exist', function () {
    $sheet = $this->service->readString("a\n1\n");

    expect(fn () => $this->service->plan($this->testApp, $sheet, objectSlug: 'fantasma'))
        ->toThrow(InvalidArgumentException::class, "no object 'fantasma'");
});

it('commits the new object as its own revertible version', function () {
    $sheet = $this->service->readString("Nombre\nAcme\n");
    $plan = $this->service->plan($this->testApp, $sheet, objectName: 'Clientes');
    $this->service->run($this->testApp, $sheet, $plan, $this->owner);

    $manifest = app(AppManifestService::class)->getActiveManifest($this->testApp->refresh());

    expect($manifest['objects'])->toHaveCount(1)
        ->and($manifest['objects'][0]['name'])->toBe('Clientes')
        ->and($plan->mode)->toBe(ImportPlan::MODE_CREATE);
});
