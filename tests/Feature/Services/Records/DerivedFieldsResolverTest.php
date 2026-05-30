<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Records\RecordQueryService;
use Illuminate\Support\Str;

function dfid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

beforeEach(function () {
    $this->service = app(RecordQueryService::class);
    $this->testApp = App::factory()->create();
});

it('resolves a lookup field through a many_to_one relation', function () {
    // CHILD object with a name field
    $childName = ['id' => dfid('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'];
    $child = ['id' => dfid('obj'), 'slug' => 'company', 'name' => 'Company', 'fields' => [$childName]];

    // PARENT object: relation to child + a lookup pulling company.nombre
    $companyRel = ['id' => dfid('fld'), 'slug' => 'company', 'name' => 'Company',
        'type' => 'relation', 'target_object_id' => $child['id'], 'cardinality' => 'many_to_one'];
    $companyName = ['id' => dfid('fld'), 'slug' => 'company_name', 'name' => 'Company name',
        'type' => 'lookup', 'readonly' => true,
        'via_relation_field_id' => $companyRel['id'], 'target_field_id' => $childName['id']];
    $parentNombre = ['id' => dfid('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'];
    $parent = ['id' => dfid('obj'), 'slug' => 'person', 'name' => 'Person',
        'fields' => [$parentNombre, $companyRel, $companyName]];

    $manifest = [
        'schema_version' => '1.0.0', 'id' => dfid('app'), 'slug' => 'x', 'name' => 'X', 'version' => 1,
        'objects' => [$child, $parent], 'pages' => [],
        'permissions' => ['roles' => [['id' => dfid('rol'), 'slug' => 'r', 'name' => 'R']]],
    ];

    $companyRecord = Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $child['id'],
        'data' => ['nombre' => 'Acme'],
    ]);
    Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $parent['id'],
        'data' => ['nombre' => 'Ana', 'company' => $companyRecord->id],
    ]);

    $rows = $this->service->query($this->testApp, ['object_id' => $parent['id']], $manifest);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->data['company_name'])->toBe('Acme');
});

it('resolves a rollup count over a one_to_many relation', function () {
    // CHILD object with an inverse pointer back to parent
    $inverseField = ['id' => dfid('fld'), 'slug' => 'cliente', 'name' => 'Cliente',
        'type' => 'relation', 'target_object_id' => 'placeholder', 'cardinality' => 'many_to_one'];
    $childMonto = ['id' => dfid('fld'), 'slug' => 'monto', 'name' => 'Monto',
        'type' => 'currency', 'currency_code' => 'MXN'];
    $childId = dfid('obj');
    $child = ['id' => $childId, 'slug' => 'venta', 'name' => 'Venta',
        'fields' => [$inverseField, $childMonto]];

    // PARENT object with a one_to_many relation + rollup
    $parentVentas = ['id' => dfid('fld'), 'slug' => 'ventas', 'name' => 'Ventas',
        'type' => 'relation', 'target_object_id' => $childId, 'cardinality' => 'one_to_many',
        'inverse_field_id' => $inverseField['id']];
    $ventasCount = ['id' => dfid('fld'), 'slug' => 'ventas_count', 'name' => 'Ventas count',
        'type' => 'rollup', 'readonly' => true,
        'via_relation_field_id' => $parentVentas['id'], 'aggregator' => 'count'];
    $ventasSum = ['id' => dfid('fld'), 'slug' => 'ventas_sum', 'name' => 'Ventas sum',
        'type' => 'rollup', 'readonly' => true,
        'via_relation_field_id' => $parentVentas['id'],
        'target_field_id' => $childMonto['id'], 'aggregator' => 'sum'];
    $parentId = dfid('obj');
    $parent = ['id' => $parentId, 'slug' => 'cliente', 'name' => 'Cliente',
        'fields' => [
            ['id' => dfid('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            $parentVentas, $ventasCount, $ventasSum,
        ]];

    // Now actually wire the inverse target back to parent (placeholder above).
    $child['fields'][0]['target_object_id'] = $parentId;

    $manifest = [
        'schema_version' => '1.0.0', 'id' => dfid('app'), 'slug' => 'x', 'name' => 'X', 'version' => 1,
        'objects' => [$child, $parent], 'pages' => [],
        'permissions' => ['roles' => [['id' => dfid('rol'), 'slug' => 'r', 'name' => 'R']]],
    ];

    $parentRecord = Record::create([
        'app_id' => $this->testApp->id,
        'object_definition_id' => $parentId,
        'data' => ['nombre' => 'Ana'],
    ]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $childId,
        'data' => ['cliente' => $parentRecord->id, 'monto' => 100]]);
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $childId,
        'data' => ['cliente' => $parentRecord->id, 'monto' => 250]]);
    // Sale that belongs to a different (non-existent) parent — must not count.
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $childId,
        'data' => ['cliente' => 'rec_other', 'monto' => 999]]);

    $rows = $this->service->query($this->testApp, ['object_id' => $parentId], $manifest);

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->data['ventas_count'])->toBe(2)
        ->and((float) $rows->first()->data['ventas_sum'])->toBe(350.0);
});

it('resolves a formula field referencing other fields', function () {
    $nombre = ['id' => dfid('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'];
    $apellido = ['id' => dfid('fld'), 'slug' => 'apellido', 'name' => 'Apellido', 'type' => 'string'];
    $fullName = ['id' => dfid('fld'), 'slug' => 'full_name', 'name' => 'Full',
        'type' => 'formula', 'readonly' => true,
        'expression' => '{{nombre}} {{upper(apellido)}}', 'return_type' => 'string'];
    $object = ['id' => dfid('obj'), 'slug' => 'p', 'name' => 'P',
        'fields' => [$nombre, $apellido, $fullName]];
    $manifest = [
        'schema_version' => '1.0.0', 'id' => dfid('app'), 'slug' => 'x', 'name' => 'X', 'version' => 1,
        'objects' => [$object], 'pages' => [],
        'permissions' => ['roles' => [['id' => dfid('rol'), 'slug' => 'r', 'name' => 'R']]],
    ];

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $object['id'],
        'data' => ['nombre' => 'Ana', 'apellido' => 'Lopez']]);

    $rows = $this->service->query($this->testApp, ['object_id' => $object['id']], $manifest);

    expect($rows->first()->data['full_name'])->toBe('Ana LOPEZ');
});

it('resolves an arithmetic formula field to a number', function () {
    $monto = ['id' => dfid('fld'), 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'];
    $conIva = ['id' => dfid('fld'), 'slug' => 'con_iva', 'name' => 'Con IVA',
        'type' => 'formula', 'readonly' => true,
        'expression' => '{{monto * 1.16}}', 'return_type' => 'number'];
    $object = ['id' => dfid('obj'), 'slug' => 'p', 'name' => 'P',
        'fields' => [$monto, $conIva]];
    $manifest = [
        'schema_version' => '1.0.0', 'id' => dfid('app'), 'slug' => 'x', 'name' => 'X', 'version' => 1,
        'objects' => [$object], 'pages' => [],
        'permissions' => ['roles' => [['id' => dfid('rol'), 'slug' => 'r', 'name' => 'R']]],
    ];

    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $object['id'],
        'data' => ['monto' => 1000]]);

    $rows = $this->service->query($this->testApp, ['object_id' => $object['id']], $manifest);

    expect((float) $rows->first()->data['con_iva'])->toBe(1160.0);
});
