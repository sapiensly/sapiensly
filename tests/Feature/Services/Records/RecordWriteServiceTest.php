<?php

use App\Models\App;
use App\Models\AppFile;
use App\Models\Record;
use App\Services\Records\RecordValidationException;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;

function wid(string $prefix): string
{
    return $prefix.'_'.strtolower((string) Str::ulid());
}

function fieldOf(string $slug, string $type, array $extra = []): array
{
    return array_merge([
        'id' => wid('fld'),
        'slug' => $slug,
        'name' => ucfirst($slug),
        'type' => $type,
    ], $extra);
}

function objectOf(string $slug, array $fields): array
{
    return [
        'id' => wid('obj'),
        'slug' => $slug,
        'name' => ucfirst($slug),
        'fields' => $fields,
    ];
}

function wmanifest(array $objects): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => wid('app'),
        'slug' => 'test',
        'name' => 'Test',
        'version' => 1,
        'objects' => $objects,
        'pages' => [],
        'permissions' => ['roles' => [['id' => wid('rol'), 'slug' => 'admin', 'name' => 'A']]],
    ];
}

beforeEach(function () {
    $this->service = new RecordWriteService;
    $this->testApp = App::factory()->create();
});

it('creates a record with valid scalar data', function () {
    $nombre = fieldOf('nombre', 'string', ['required' => true, 'max_length' => 100]);
    $monto = fieldOf('monto', 'currency', ['currency_code' => 'MXN']);
    $object = objectOf('cliente', [$nombre, $monto]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], [
        'nombre' => 'Ana',
        'monto' => '1500.50',
    ]);

    expect($record)->toBeInstanceOf(Record::class)
        ->and($record->data['nombre'])->toBe('Ana')
        ->and($record->data['monto'])->toBe(1500.5)
        ->and($record->app_id)->toBe($this->testApp->id);
});

it('rejects a required field that is missing or empty', function () {
    $nombre = fieldOf('nombre', 'string', ['required' => true]);
    $object = objectOf('o', [$nombre]);
    $manifest = wmanifest([$object]);

    try {
        $this->service->create($this->testApp, $manifest, $object['id'], ['nombre' => '']);
        $this->fail('expected exception');
    } catch (RecordValidationException $e) {
        expect($e->errors)->toHaveKey('nombre');
    }
});

it('rejects unknown fields the manifest does not declare', function () {
    $object = objectOf('o', [fieldOf('nombre', 'string')]);
    $manifest = wmanifest([$object]);

    try {
        $this->service->create($this->testApp, $manifest, $object['id'], ['nombre' => 'X', 'ghost' => 'Y']);
        $this->fail('expected exception');
    } catch (RecordValidationException $e) {
        expect($e->errors)->toHaveKey('ghost');
    }
});

it('rejects a number outside min/max', function () {
    $edad = fieldOf('edad', 'number', ['min' => 18, 'max' => 99]);
    $object = objectOf('o', [$edad]);
    $manifest = wmanifest([$object]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], ['edad' => 12]))
        ->toThrow(RecordValidationException::class);
});

it('rejects a string that violates max_length or pattern', function () {
    $cp = fieldOf('cp', 'string', ['pattern' => '^\d{5}$']);
    $object = objectOf('o', [$cp]);
    $manifest = wmanifest([$object]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], ['cp' => 'AB123']))
        ->toThrow(RecordValidationException::class);
});

it('rejects a single_select value not in the options list', function () {
    $estado = fieldOf('estado', 'single_select', ['options' => [
        ['id' => 'opt_a', 'value' => 'open', 'label' => 'Open'],
        ['id' => 'opt_b', 'value' => 'closed', 'label' => 'Closed'],
    ]]);
    $object = objectOf('o', [$estado]);
    $manifest = wmanifest([$object]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], ['estado' => 'magic']))
        ->toThrow(RecordValidationException::class);
});

it('accepts a multi_select where every value is in the catalog', function () {
    $tags = fieldOf('tags', 'multi_select', ['options' => [
        ['id' => 'opt_a', 'value' => 'a', 'label' => 'A'],
        ['id' => 'opt_b', 'value' => 'b', 'label' => 'B'],
        ['id' => 'opt_c', 'value' => 'c', 'label' => 'C'],
    ]]);
    $object = objectOf('o', [$tags]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], ['tags' => ['a', 'c']]);
    expect($record->data['tags'])->toBe(['a', 'c']);
});

it('normalises date fields to ISO YYYY-MM-DD', function () {
    $f = fieldOf('fecha', 'date');
    $object = objectOf('o', [$f]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], ['fecha' => '05/23/2026']);
    expect($record->data['fecha'])->toBe('2026-05-23');
});

it('rejects an unparseable date', function () {
    $f = fieldOf('fecha', 'date');
    $object = objectOf('o', [$f]);
    $manifest = wmanifest([$object]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], ['fecha' => 'not-a-date']))
        ->toThrow(RecordValidationException::class);
});

it('update merges partial data without touching other fields', function () {
    $nombre = fieldOf('nombre', 'string');
    $monto = fieldOf('monto', 'currency', ['currency_code' => 'MXN']);
    $object = objectOf('o', [$nombre, $monto]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], [
        'nombre' => 'Ana', 'monto' => 1000,
    ]);

    $updated = $this->service->update($this->testApp, $manifest, $record, ['monto' => 2500]);

    expect($updated->data['nombre'])->toBe('Ana')
        ->and((float) $updated->data['monto'])->toBe(2500.0);
});

it('update does not require fields that are not being sent', function () {
    $required = fieldOf('nombre', 'string', ['required' => true]);
    $monto = fieldOf('monto', 'number');
    $object = objectOf('o', [$required, $monto]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], [
        'nombre' => 'Ana', 'monto' => 5,
    ]);

    $updated = $this->service->update($this->testApp, $manifest, $record, ['monto' => 10]);
    expect((float) $updated->data['monto'])->toBe(10.0);
});

it('update rejects record from another app', function () {
    $object = objectOf('o', [fieldOf('nombre', 'string')]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], ['nombre' => 'X']);
    $otherApp = App::factory()->create();

    expect(fn () => $this->service->update($otherApp, $manifest, $record, ['nombre' => 'Y']))
        ->toThrow(InvalidArgumentException::class);
});

it('deletes a record', function () {
    $object = objectOf('o', [fieldOf('nombre', 'string')]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], ['nombre' => 'X']);
    $this->service->delete($record);

    expect(Record::query()->find($record->id))->toBeNull();
});

it('accepts a valid rating value and rejects out-of-range', function () {
    $rating = fieldOf('puntuacion', 'rating', ['max' => 5]);
    $obj = objectOf('review', [$rating]);
    $manifest = wmanifest([$obj]);

    $rec = $this->service->create($this->testApp, $manifest, $obj['id'], ['puntuacion' => 4]);
    expect($rec->data['puntuacion'])->toBe(4);

    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], ['puntuacion' => 9]))
        ->toThrow(RecordValidationException::class);
});

it('accepts a valid slider value and rejects out-of-range', function () {
    $slider = fieldOf('progress', 'slider', ['min' => 0, 'max' => 100, 'step' => 1]);
    $obj = objectOf('task', [$slider]);
    $manifest = wmanifest([$obj]);

    $rec = $this->service->create($this->testApp, $manifest, $obj['id'], ['progress' => 75]);
    // JSONB round-trip can collapse 75.0 → 75 (int); just assert numeric equality.
    expect((float) $rec->data['progress'])->toBe(75.0);

    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], ['progress' => 150]))
        ->toThrow(RecordValidationException::class);
});

it('accepts a valid date_range and rejects from > to', function () {
    $range = fieldOf('window', 'date_range');
    $obj = objectOf('event', [$range]);
    $manifest = wmanifest([$obj]);

    $rec = $this->service->create(
        $this->testApp,
        $manifest,
        $obj['id'],
        ['window' => ['from' => '2025-01-01', 'to' => '2025-12-31']],
    );
    expect($rec->data['window'])->toBe(['from' => '2025-01-01', 'to' => '2025-12-31']);

    expect(fn () => $this->service->create(
        $this->testApp,
        $manifest,
        $obj['id'],
        ['window' => ['from' => '2025-12-31', 'to' => '2025-01-01']],
    ))->toThrow(RecordValidationException::class);
});

it('rejects a date_range missing from or to', function () {
    $range = fieldOf('window', 'date_range');
    $obj = objectOf('event', [$range]);
    $manifest = wmanifest([$obj]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], ['window' => ['from' => '2025-01-01']]))
        ->toThrow(RecordValidationException::class);
});

it('accepts a file value referencing an existing AppFile row', function () {
    $file = fieldOf('attachment', 'file', ['max_size_mb' => 10]);
    $obj = objectOf('docs', [$file]);
    $manifest = wmanifest([$obj]);

    $row = AppFile::create([
        'id' => 'fil_'.strtolower((string) Str::ulid()),
        'app_id' => $this->testApp->id,
        'disk' => 'local',
        'storage_path' => 'test/x.pdf',
        'original_name' => 'x.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 1234,
    ]);

    $rec = $this->service->create($this->testApp, $manifest, $obj['id'], [
        'attachment' => ['file_id' => $row->id, 'url' => '/test'],
    ]);

    expect($rec->data['attachment']['file_id'])->toBe($row->id)
        ->and($rec->data['attachment']['original_name'])->toBe('x.pdf')
        ->and($rec->data['attachment']['mime'])->toBe('application/pdf')
        ->and($rec->data['attachment']['size_bytes'])->toBe(1234);
});

it('rejects a file value whose file_id does not exist', function () {
    $file = fieldOf('attachment', 'file');
    $obj = objectOf('docs', [$file]);
    $manifest = wmanifest([$obj]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], [
        'attachment' => ['file_id' => 'fil_does_not_exist_xyz'],
    ]))->toThrow(RecordValidationException::class);
});

it('rejects a file whose MIME is not in the field allowlist', function () {
    $file = fieldOf('attachment', 'file', ['mime_types' => ['image/*']]);
    $obj = objectOf('docs', [$file]);
    $manifest = wmanifest([$obj]);

    $row = AppFile::create([
        'id' => 'fil_'.strtolower((string) Str::ulid()),
        'app_id' => $this->testApp->id,
        'disk' => 'local',
        'storage_path' => 'test/x.pdf',
        'original_name' => 'x.pdf',
        'mime' => 'application/pdf',
        'size_bytes' => 100,
    ]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], [
        'attachment' => ['file_id' => $row->id],
    ]))->toThrow(RecordValidationException::class);
});

it('accepts a file whose MIME matches a wildcard pattern', function () {
    $file = fieldOf('photo', 'file', ['mime_types' => ['image/*']]);
    $obj = objectOf('photos', [$file]);
    $manifest = wmanifest([$obj]);

    $row = AppFile::create([
        'id' => 'fil_'.strtolower((string) Str::ulid()),
        'app_id' => $this->testApp->id,
        'disk' => 'local',
        'storage_path' => 'test/p.png',
        'original_name' => 'p.png',
        'mime' => 'image/png',
        'size_bytes' => 50,
    ]);

    $rec = $this->service->create($this->testApp, $manifest, $obj['id'], [
        'photo' => ['file_id' => $row->id],
    ]);

    expect($rec->data['photo']['mime'])->toBe('image/png');
});

it('rejects a file that exceeds the per-field max_size_mb', function () {
    $file = fieldOf('attachment', 'file', ['max_size_mb' => 1]);
    $obj = objectOf('docs', [$file]);
    $manifest = wmanifest([$obj]);

    $row = AppFile::create([
        'id' => 'fil_'.strtolower((string) Str::ulid()),
        'app_id' => $this->testApp->id,
        'disk' => 'local',
        'storage_path' => 'test/big.bin',
        'original_name' => 'big.bin',
        'mime' => 'application/octet-stream',
        'size_bytes' => 2 * 1024 * 1024, // 2 MB
    ]);

    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], [
        'attachment' => ['file_id' => $row->id],
    ]))->toThrow(RecordValidationException::class);
});

it('sanitises rich_text HTML on save, stripping scripts and event handlers', function () {
    $description = fieldOf('description', 'rich_text');
    $obj = objectOf('docs', [$description]);
    $manifest = wmanifest([$obj]);

    $dirty = '<p>Hello <strong>world</strong><script>alert(1)</script></p><div onclick="evil()">click</div>';

    $rec = $this->service->create($this->testApp, $manifest, $obj['id'], [
        'description' => $dirty,
    ]);

    expect($rec->data['description'])->toContain('<strong>world</strong>')
        ->and($rec->data['description'])->not->toContain('<script')
        ->and($rec->data['description'])->not->toContain('onclick')
        ->and($rec->data['description'])->not->toContain('alert(1)');
});

it('rejects rich_text whose plain-text length exceeds max_length', function () {
    $description = fieldOf('description', 'rich_text', ['max_length' => 10]);
    $obj = objectOf('docs', [$description]);
    $manifest = wmanifest([$obj]);

    // Heavy bold tags but 20 chars of actual text — should fail max_length=10.
    expect(fn () => $this->service->create($this->testApp, $manifest, $obj['id'], [
        'description' => '<p><strong>aaaaaaaaaaaaaaaaaaaa</strong></p>',
    ]))->toThrow(RecordValidationException::class);
});

it('accepts rich_text within max_length even with heavy markup', function () {
    $description = fieldOf('description', 'rich_text', ['max_length' => 20]);
    $obj = objectOf('docs', [$description]);
    $manifest = wmanifest([$obj]);

    $rec = $this->service->create($this->testApp, $manifest, $obj['id'], [
        'description' => '<p><strong>short</strong></p>',
    ]);

    expect($rec->data['description'])->toContain('<strong>short</strong>');
});

/**
 * A parent (categoria) + child (producto) whose `categoria` relation targets it.
 *
 * @return array{0: array<string,mixed>, 1: array<string,mixed>, 2: array<string,mixed>}
 */
function relationFixture(): array
{
    $cat = objectOf('categoria', [fieldOf('nombre', 'string')]);
    $prod = objectOf('producto', [
        fieldOf('titulo', 'string'),
        fieldOf('categoria', 'relation', ['target_object_id' => $cat['id'], 'cardinality' => 'many_to_one']),
    ]);

    return [$cat, $prod, wmanifest([$cat, $prod])];
}

it('keeps a relation given as an existing target record id', function () {
    [$cat, $prod, $manifest] = relationFixture();
    $catRec = Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $cat['id'], 'data' => ['nombre' => 'Electronics']]);

    $rec = $this->service->create($this->testApp, $manifest, $prod['id'], [
        'titulo' => 'Laptop', 'categoria' => $catRec->id,
    ]);

    expect($rec->data['categoria'])->toBe($catRec->id);
});

it('resolves a relation given by the target record name (case-insensitively)', function () {
    [$cat, $prod, $manifest] = relationFixture();
    $catRec = Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $cat['id'], 'data' => ['nombre' => 'Electronics']]);

    $rec = $this->service->create($this->testApp, $manifest, $prod['id'], [
        'titulo' => 'Laptop', 'categoria' => 'electronics',
    ]);

    expect($rec->data['categoria'])->toBe($catRec->id);
});

it('rejects a relation that matches no record — never storing a dangling fk', function () {
    [$cat, $prod, $manifest] = relationFixture();
    Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $cat['id'], 'data' => ['nombre' => 'Electronics']]);

    // The target OBJECT id (obj_…) — the exact value the seeding bug stored — is
    // not a record, so it must be rejected rather than persisted.
    expect(fn () => $this->service->create($this->testApp, $manifest, $prod['id'], [
        'titulo' => 'Laptop', 'categoria' => $cat['id'],
    ]))->toThrow(RecordValidationException::class);

    expect(Record::where('object_definition_id', $prod['id'])->count())->toBe(0);
});

it('resolves a many_to_many relation from a list of names', function () {
    $tag = objectOf('tag', [fieldOf('nombre', 'string')]);
    $post = objectOf('post', [
        fieldOf('titulo', 'string'),
        fieldOf('tags', 'relation', ['target_object_id' => $tag['id'], 'cardinality' => 'many_to_many']),
    ]);
    $manifest = wmanifest([$tag, $post]);
    $a = Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $tag['id'], 'data' => ['nombre' => 'News']]);
    $b = Record::create(['app_id' => $this->testApp->id, 'object_definition_id' => $tag['id'], 'data' => ['nombre' => 'Sports']]);

    $rec = $this->service->create($this->testApp, $manifest, $post['id'], [
        'titulo' => 'Hello', 'tags' => ['News', $b->id],
    ]);

    expect($rec->data['tags'])->toBe([$a->id, $b->id]);
});

it('validates the contact trio: email, url and phone formats', function () {
    $object = objectOf('contacto', [
        fieldOf('correo', 'email'),
        fieldOf('sitio', 'url'),
        fieldOf('telefono', 'phone'),
    ]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], [
        'correo' => 'ana@nebula.mx',
        'sitio' => 'https://nebula.mx',
        'telefono' => '+52 (55) 1234-5678',
    ]);

    expect($record->data['correo'])->toBe('ana@nebula.mx')
        ->and($record->data['sitio'])->toBe('https://nebula.mx')
        ->and($record->data['telefono'])->toBe('+52 (55) 1234-5678');

    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], [
        'correo' => 'no-es-un-correo',
    ]))->toThrow(RecordValidationException::class);

    // url must be http(s) — javascript:/ftp: shapes are rejected outright.
    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], [
        'sitio' => 'javascript:alert(1)',
    ]))->toThrow(RecordValidationException::class);

    expect(fn () => $this->service->create($this->testApp, $manifest, $object['id'], [
        'telefono' => 'llámame cuando puedas',
    ]))->toThrow(RecordValidationException::class);
});

it('accepts an empty optional contact field', function () {
    $object = objectOf('contacto', [fieldOf('correo', 'email')]);
    $manifest = wmanifest([$object]);

    $record = $this->service->create($this->testApp, $manifest, $object['id'], ['correo' => '']);

    expect($record->data['correo'] ?? null)->toBeNull();
});
