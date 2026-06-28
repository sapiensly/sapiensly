<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordWriteService;

/**
 * @return array<string, mixed>
 */
function tasksManifest(): array
{
    return [
        'objects' => [[
            'id' => 'obj_tasks_00001',
            'slug' => 'tasks',
            'name' => 'Tasks',
            'fields' => [
                ['id' => 'fld_task_title01', 'slug' => 'title', 'name' => 'Title', 'type' => 'string'],
            ],
        ]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'tasks_app',
    ]);
    $this->manifest = tasksManifest();

    $writer = app(RecordWriteService::class);
    $this->a = $writer->create($this->appModel, $this->manifest, 'obj_tasks_00001', ['title' => 'A'], $this->user);
    $this->b = $writer->create($this->appModel, $this->manifest, 'obj_tasks_00001', ['title' => 'B'], $this->user);
    $this->c = $writer->create($this->appModel, $this->manifest, 'obj_tasks_00001', ['title' => 'C'], $this->user);
});

it('fetches a single record by id', function () {
    $rows = app(RecordQueryService::class)->query($this->appModel, [
        'object_id' => 'obj_tasks_00001',
        'filter' => ['op' => 'eq', 'field_id' => 'id', 'value' => $this->b->id],
    ], $this->manifest);

    expect($rows)->toHaveCount(1);
    expect($rows->first()->id)->toBe($this->b->id);
    expect($rows->first()->data['title'])->toBe('B');
});

it('fetches several records by id with the in operator', function () {
    $rows = app(RecordQueryService::class)->query($this->appModel, [
        'object_id' => 'obj_tasks_00001',
        'filter' => ['op' => 'in', 'field_id' => 'id', 'value' => [$this->a->id, $this->c->id]],
    ], $this->manifest);

    expect($rows->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->a->id, $this->c->id])->sort()->values()->all());
});

it('validator resolves a filter referencing the id system field', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_getbyid001',
        'slug' => 'tasks_app',
        'name' => 'Tasks',
        'version' => 1,
        'objects' => tasksManifest()['objects'],
        'pages' => [[
            'id' => 'pag_list_000001',
            'slug' => 'list',
            'name' => 'List',
            'path' => '/',
            'blocks' => [[
                'id' => 'blk_table_00001',
                'type' => 'table',
                'data_source' => [
                    'object_id' => 'obj_tasks_00001',
                    'filter' => ['op' => 'eq', 'field_id' => 'id', 'value_expression' => '{{params.id}}'],
                ],
                'columns' => [
                    ['id' => 'col_id_000001', 'field_id' => 'id'],
                    ['id' => 'col_title00001', 'field_id' => 'fld_task_title01'],
                ],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_admin_0001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    $unresolved = collect($result->errors)->filter(fn ($e) => $e->code === 'unresolved_ref');
    expect($unresolved)->toBeEmpty();
});
