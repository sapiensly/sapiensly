<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Records\RecordQueryService;
use Illuminate\Support\Str;

function fld(string $slug, string $type, array $extra = []): array
{
    return array_merge([
        'id' => 'fld_'.strtolower((string) Str::ulid()),
        'slug' => $slug,
        'name' => ucfirst($slug),
        'type' => $type,
    ], $extra);
}

function objectDef(string $slug, array $fields): array
{
    return [
        'id' => 'obj_'.strtolower((string) Str::ulid()),
        'slug' => $slug,
        'name' => ucfirst($slug),
        'fields' => $fields,
    ];
}

function manifestFor(array $objects): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_'.strtolower((string) Str::ulid()),
        'slug' => 'test_app',
        'name' => 'Test',
        'version' => 1,
        'objects' => $objects,
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin'],
            ],
        ],
    ];
}

function makeRecord(App $app, array $object, array $data): Record
{
    return Record::create([
        'app_id' => $app->id,
        'object_definition_id' => $object['id'],
        'data' => $data,
    ]);
}

beforeEach(function () {
    $this->service = app(RecordQueryService::class);
    $this->testApp = App::factory()->create();
    $this->nameField = fld('nombre', 'string');
    $this->amountField = fld('monto', 'currency', ['currency_code' => 'MXN']);
    $this->ageField = fld('edad', 'number');
    $this->activeField = fld('activo', 'boolean');
    $this->createdField = fld('fecha', 'date');
    $this->statusField = fld('estado', 'single_select', ['options' => [
        ['id' => 'opt_a', 'value' => 'open', 'label' => 'Open'],
        ['id' => 'opt_b', 'value' => 'closed', 'label' => 'Closed'],
    ]]);
    $this->object = objectDef('cliente', [
        $this->nameField, $this->amountField, $this->ageField,
        $this->activeField, $this->createdField, $this->statusField,
    ]);
    $this->manifest = manifestFor([$this->object]);
});

it('scopes results to app_id + object_definition_id', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'A']);
    makeRecord($this->testApp, $this->object, ['nombre' => 'B']);

    $otherApp = App::factory()->create();
    makeRecord($otherApp, $this->object, ['nombre' => 'X']);
    makeRecord($this->testApp, objectDef('otra', [fld('a', 'string')]), ['a' => 'y']);

    $results = $this->service->query($this->testApp, ['object_id' => $this->object['id']], $this->manifest);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('data.nombre')->sort()->values()->all())->toBe(['A', 'B']);
});

it('filters by eq on a string field', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'Ana']);
    makeRecord($this->testApp, $this->object, ['nombre' => 'Beto']);

    $results = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'Ana'],
    ], $this->manifest);

    expect($results)->toHaveCount(1)
        ->and($results->first()->data['nombre'])->toBe('Ana');
});

it('skips a value condition whose expression resolves to empty (page filter no-op)', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'Ana']);
    makeRecord($this->testApp, $this->object, ['nombre' => 'Beto']);

    $filter = ['op' => 'contains', 'field_id' => $this->nameField['id'], 'value_expression' => '{{params.q}}'];

    // Unset/empty param → condition skipped → the unfiltered set shows.
    $all = $this->service->query(
        $this->testApp,
        ['object_id' => $this->object['id'], 'filter' => $filter],
        $this->manifest,
        ['params' => ['q' => '']],
    );
    expect($all)->toHaveCount(2);

    // Filled param → condition applies.
    $filtered = $this->service->query(
        $this->testApp,
        ['object_id' => $this->object['id'], 'filter' => $filter],
        $this->manifest,
        ['params' => ['q' => 'Ana']],
    );
    expect($filtered)->toHaveCount(1)
        ->and($filtered->first()->data['nombre'])->toBe('Ana');
});

it('filters with gt/gte/lt/lte on a numeric field', function () {
    makeRecord($this->testApp, $this->object, ['edad' => 20]);
    makeRecord($this->testApp, $this->object, ['edad' => 30]);
    makeRecord($this->testApp, $this->object, ['edad' => 40]);

    $gt = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'gt', 'field_id' => $this->ageField['id'], 'value' => 25],
    ], $this->manifest);
    expect($gt)->toHaveCount(2);

    $between = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'between', 'field_id' => $this->ageField['id'], 'value' => [25, 35]],
    ], $this->manifest);
    expect($between)->toHaveCount(1)
        ->and($between->first()->data['edad'])->toBe(30);
});

it('filters with in / not_in', function () {
    makeRecord($this->testApp, $this->object, ['estado' => 'open']);
    makeRecord($this->testApp, $this->object, ['estado' => 'closed']);
    makeRecord($this->testApp, $this->object, ['estado' => 'open']);

    $in = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'in', 'field_id' => $this->statusField['id'], 'value' => ['open']],
    ], $this->manifest);
    expect($in)->toHaveCount(2);

    $notIn = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'not_in', 'field_id' => $this->statusField['id'], 'value' => ['open']],
    ], $this->manifest);
    expect($notIn)->toHaveCount(1);
});

it('filters with contains / starts_with / ends_with', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'Ana Lopez']);
    makeRecord($this->testApp, $this->object, ['nombre' => 'Beto Lopez']);
    makeRecord($this->testApp, $this->object, ['nombre' => 'Carla Rios']);

    $contains = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'contains', 'field_id' => $this->nameField['id'], 'value' => 'Lopez'],
    ], $this->manifest);
    expect($contains)->toHaveCount(2);

    $starts = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'starts_with', 'field_id' => $this->nameField['id'], 'value' => 'Ana'],
    ], $this->manifest);
    expect($starts)->toHaveCount(1);

    $ends = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'ends_with', 'field_id' => $this->nameField['id'], 'value' => 'Rios'],
    ], $this->manifest);
    expect($ends)->toHaveCount(1);
});

it('filters with is_null / is_not_null', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'X']);
    makeRecord($this->testApp, $this->object, ['nombre' => null]);
    makeRecord($this->testApp, $this->object, []); // key missing entirely

    $isNull = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'is_null', 'field_id' => $this->nameField['id']],
    ], $this->manifest);
    expect($isNull)->toHaveCount(2);

    $isNotNull = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'is_not_null', 'field_id' => $this->nameField['id']],
    ], $this->manifest);
    expect($isNotNull)->toHaveCount(1);
});

it('combines filters with and / or / not', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'A', 'edad' => 25]);
    makeRecord($this->testApp, $this->object, ['nombre' => 'B', 'edad' => 35]);
    makeRecord($this->testApp, $this->object, ['nombre' => 'C', 'edad' => 45]);

    $andResult = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => [
            'op' => 'and',
            'conditions' => [
                ['op' => 'gt', 'field_id' => $this->ageField['id'], 'value' => 20],
                ['op' => 'lt', 'field_id' => $this->ageField['id'], 'value' => 40],
            ],
        ],
    ], $this->manifest);
    expect($andResult)->toHaveCount(2);

    $orResult = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => [
            'op' => 'or',
            'conditions' => [
                ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'A'],
                ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'C'],
            ],
        ],
    ], $this->manifest);
    expect($orResult)->toHaveCount(2);

    $notResult = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => [
            'op' => 'not',
            'condition' => ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => 'A'],
        ],
    ], $this->manifest);
    expect($notResult)->toHaveCount(2);
});

it('resolves value_expression against context', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'X', 'edad' => 7]);
    makeRecord($this->testApp, $this->object, ['nombre' => 'Y', 'edad' => 9]);

    $results = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => [
            'op' => 'eq',
            'field_id' => $this->nameField['id'],
            'value_expression' => '{{params.target}}',
        ],
    ], $this->manifest, ['params' => ['target' => 'Y']]);

    expect($results)->toHaveCount(1)
        ->and($results->first()->data['edad'])->toBe(9);
});

it('sorts asc and desc and respects limit/offset', function () {
    for ($i = 1; $i <= 5; $i++) {
        makeRecord($this->testApp, $this->object, ['edad' => $i * 10]);
    }

    $asc = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'sort' => [['field_id' => $this->ageField['id'], 'direction' => 'asc']],
        'limit' => 3,
    ], $this->manifest);
    expect($asc->pluck('data.edad')->all())->toBe([10, 20, 30]);

    $desc = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'sort' => [['field_id' => $this->ageField['id'], 'direction' => 'desc']],
        'limit' => 2,
        'offset' => 1,
    ], $this->manifest);
    expect($desc->pluck('data.edad')->all())->toBe([40, 30]);
});

it('aggregates count', function () {
    makeRecord($this->testApp, $this->object, ['estado' => 'open']);
    makeRecord($this->testApp, $this->object, ['estado' => 'open']);
    makeRecord($this->testApp, $this->object, ['estado' => 'closed']);

    $all = $this->service->aggregate($this->testApp, ['object_id' => $this->object['id']], 'count', null, $this->manifest);
    expect($all)->toBe(3);

    $filtered = $this->service->aggregate(
        $this->testApp,
        [
            'object_id' => $this->object['id'],
            'filter' => ['op' => 'eq', 'field_id' => $this->statusField['id'], 'value' => 'open'],
        ],
        'count',
        null,
        $this->manifest,
    );
    expect($filtered)->toBe(2);
});

it('aggregates sum / avg / min / max on a numeric field', function () {
    makeRecord($this->testApp, $this->object, ['monto' => 100]);
    makeRecord($this->testApp, $this->object, ['monto' => 200]);
    makeRecord($this->testApp, $this->object, ['monto' => 700]);

    $q = ['object_id' => $this->object['id']];
    expect($this->service->aggregate($this->testApp, $q, 'sum', $this->amountField['id'], $this->manifest))->toBe(1000.0)
        ->and($this->service->aggregate($this->testApp, $q, 'avg', $this->amountField['id'], $this->manifest))
        ->toEqualWithDelta(333.333, 0.01)
        ->and($this->service->aggregate($this->testApp, $q, 'min', $this->amountField['id'], $this->manifest))->toBe(100.0)
        ->and($this->service->aggregate($this->testApp, $q, 'max', $this->amountField['id'], $this->manifest))->toBe(700.0);
});

it('rejects aggregating sum on a non-numeric field', function () {
    expect(fn () => $this->service->aggregate(
        $this->testApp,
        ['object_id' => $this->object['id']],
        'sum',
        $this->nameField['id'],
        $this->manifest,
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects an unknown filter op', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'A']);
    expect(fn () => $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'magic', 'field_id' => $this->nameField['id'], 'value' => 'A'],
    ], $this->manifest))->toThrow(InvalidArgumentException::class);
});

it('throws when query references an object_id missing from the manifest', function () {
    expect(fn () => $this->service->query(
        $this->testApp,
        ['object_id' => 'obj_'.strtolower((string) Str::ulid())],
        $this->manifest,
    ))->toThrow(RuntimeException::class);
});

it('parameter-binds values to defeat SQL injection in eq filter', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => "O'Brien"]);
    makeRecord($this->testApp, $this->object, ['nombre' => "'); drop table records;--"]);

    $results = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'eq', 'field_id' => $this->nameField['id'], 'value' => "O'Brien"],
    ], $this->manifest);

    expect($results)->toHaveCount(1)
        ->and(Record::count())->toBe(2); // table still exists
});

it('rejects field slug that fails the safe-slug guard', function () {
    $bad = fld('weird-slug', 'string'); // dashes — would never pass ManifestValidator
    $obj = objectDef('o', [$bad]);
    $manifest = manifestFor([$obj]);
    makeRecord($this->testApp, $obj, ['weird-slug' => 'x']);

    expect(fn () => $this->service->query($this->testApp, [
        'object_id' => $obj['id'],
        'filter' => ['op' => 'eq', 'field_id' => $bad['id'], 'value' => 'x'],
    ], $manifest))->toThrow(InvalidArgumentException::class);
});

it('filters records by sys_created_at >= 30 days ago', function () {
    $old = makeRecord($this->testApp, $this->object, ['nombre' => 'Old']);
    $old->created_at = now()->subDays(60);
    $old->save();
    $recent = makeRecord($this->testApp, $this->object, ['nombre' => 'Recent']);
    $recent->created_at = now()->subDays(5);
    $recent->save();

    $results = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => [
            'op' => 'gte',
            'field_id' => 'sys_created_at',
            'value' => now()->subDays(30)->toIso8601String(),
        ],
    ], $this->manifest);

    expect($results)->toHaveCount(1)
        ->and($results->first()->data['nombre'])->toBe('Recent');
});

it('sorts records by sys_created_at desc', function () {
    $first = makeRecord($this->testApp, $this->object, ['nombre' => 'First']);
    $first->created_at = now()->subDays(10);
    $first->save();
    $second = makeRecord($this->testApp, $this->object, ['nombre' => 'Second']);
    $second->created_at = now()->subDays(2);
    $second->save();

    $results = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'sort' => [['field_id' => 'sys_created_at', 'direction' => 'desc']],
    ], $this->manifest);

    expect($results->pluck('data.nombre')->values()->all())->toBe(['Second', 'First']);
});

it('counts records filtered by sys_created_at (works for sparkline-style queries)', function () {
    foreach (range(1, 3) as $i) {
        $r = makeRecord($this->testApp, $this->object, ['nombre' => "R{$i}"]);
        $r->created_at = now()->subDays($i);
        $r->save();
    }
    $old = makeRecord($this->testApp, $this->object, ['nombre' => 'TooOld']);
    $old->created_at = now()->subDays(40);
    $old->save();

    $count = $this->service->aggregate($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => [
            'op' => 'gte',
            'field_id' => 'sys_created_at',
            'value' => now()->subDays(30)->toIso8601String(),
        ],
    ], 'count', null, $this->manifest);

    expect($count)->toBe(3);
});

it('treats is_null on sys_created_at against the real column (always non-null)', function () {
    makeRecord($this->testApp, $this->object, ['nombre' => 'A']);

    $results = $this->service->query($this->testApp, [
        'object_id' => $this->object['id'],
        'filter' => ['op' => 'is_not_null', 'field_id' => 'sys_created_at'],
    ], $this->manifest);

    expect($results)->toHaveCount(1);
});

it('filters and aggregates rating fields as numeric', function () {
    $ratingField = fld('puntuacion', 'rating', ['max' => 5]);
    $obj = objectDef('reviews', [$this->nameField, $ratingField]);
    $manifest = manifestFor([$obj]);

    foreach ([1, 3, 5, 5, 4] as $score) {
        makeRecord($this->testApp, $obj, ['nombre' => "r{$score}", 'puntuacion' => $score]);
    }

    $avg = $this->service->aggregate($this->testApp, ['object_id' => $obj['id']], 'avg', $ratingField['id'], $manifest);
    expect($avg)->toBe(3.6);

    $high = $this->service->query($this->testApp, [
        'object_id' => $obj['id'],
        'filter' => ['op' => 'gte', 'field_id' => $ratingField['id'], 'value' => 4],
    ], $manifest);
    expect($high)->toHaveCount(3);
});

it('filters and aggregates slider fields as numeric', function () {
    $progress = fld('progreso', 'slider', ['min' => 0, 'max' => 100, 'step' => 1]);
    $obj = objectDef('tasks', [$this->nameField, $progress]);
    $manifest = manifestFor([$obj]);

    foreach ([10, 50, 90] as $v) {
        makeRecord($this->testApp, $obj, ['nombre' => "t{$v}", 'progreso' => $v]);
    }

    $max = $this->service->aggregate($this->testApp, ['object_id' => $obj['id']], 'max', $progress['id'], $manifest);
    expect($max)->toBe(90.0);
});
