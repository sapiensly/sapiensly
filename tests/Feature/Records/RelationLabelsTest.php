<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;

/**
 * A relation, shown as the record it points at.
 *
 * A cell holding a belongs-to used to print the stored value, which is a
 * `rec_01k…` id — the one thing on screen a reader can do nothing with. The
 * browser never receives anything else (the id IS the stored value), so the
 * label has to be resolved here, and it has to reach every surface that shows
 * relations rather than the one that got noticed first.
 *
 * @return array<string, mixed>
 */
function relationLabelManifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'labels_app',
        'name' => 'Labels',
        'version' => 1,
        'objects' => [
            [
                'id' => 'obj_lbl_tickets1',
                'slug' => 'tickets',
                'name' => 'Ticket',
                'fields' => [
                    ['id' => 'fld_lbl_asunto01', 'slug' => 'asunto', 'name' => 'Asunto', 'type' => 'string'],
                    [
                        'id' => 'fld_lbl_replies1', 'slug' => 'respuestas', 'name' => 'Respuestas', 'type' => 'relation',
                        'cardinality' => 'one_to_many', 'target_object_id' => 'obj_lbl_replies1',
                        'inverse_field_id' => 'fld_lbl_ticket001',
                    ],
                ],
            ],
            [
                'id' => 'obj_lbl_replies1',
                'slug' => 'respuestas',
                'name' => 'Respuesta',
                'fields' => [
                    ['id' => 'fld_lbl_mensaje1', 'slug' => 'mensaje', 'name' => 'Mensaje', 'type' => 'string'],
                    [
                        'id' => 'fld_lbl_ticket001', 'slug' => 'ticket', 'name' => 'Ticket', 'type' => 'relation',
                        'cardinality' => 'many_to_one', 'target_object_id' => 'obj_lbl_tickets1',
                        'inverse_field_id' => 'fld_lbl_replies1', 'on_delete' => 'set_null',
                    ],
                ],
            ],
        ],
        'pages' => [[
            'id' => 'pag_lbl_00000001',
            'slug' => 'respuestas',
            'path' => '/respuestas',
            'name' => 'Respuestas',
            'blocks' => [
                [
                    'id' => 'blk_lbl_table001',
                    'type' => 'table',
                    'data_source' => ['object_id' => 'obj_lbl_replies1'],
                    'columns' => [
                        ['id' => 'col_lbl_mensaje', 'field_id' => 'fld_lbl_mensaje1'],
                        ['id' => 'col_lbl_ticket0', 'field_id' => 'fld_lbl_ticket001'],
                    ],
                ],
                [
                    'id' => 'blk_lbl_detail01',
                    'type' => 'record_detail',
                    'object_id' => 'obj_lbl_replies1',
                    'record_id_expression' => '{{params.id}}',
                    'fields' => [['field_id' => 'fld_lbl_ticket001']],
                ],
            ],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'labels_app',
    ]);
    $this->manifest = relationLabelManifest($this->appModel->id);
    app(AppManifestService::class)->createVersion($this->appModel, $this->manifest, $this->user);

    $writer = app(RecordWriteService::class);
    $this->ticket = $writer->create($this->appModel, $this->manifest, 'obj_lbl_tickets1', [
        'asunto' => 'No puedo iniciar sesion',
    ], $this->user);
    $this->reply = $writer->create($this->appModel, $this->manifest, 'obj_lbl_replies1', [
        'mensaje' => 'Ya la reiniciamos',
        'ticket' => $this->ticket->id,
    ], $this->user);
});

it('labels a relation in a table with the record it points at', function () {
    $blocks = $this->manifest['pages'][0]['blocks'];

    $data = app(BlockDataResolver::class)->resolve(
        $this->appModel,
        [$blocks[0]],
        $this->manifest,
        [],
    );

    $row = $data['blk_lbl_table001']['rows'][0];

    // The id stays where it was — filters and row actions address the record by
    // it — and the readable form travels beside it.
    expect($row['data']['ticket'])->toBe($this->ticket->id)
        ->and($row['labels']['ticket'])->toBe('No puedo iniciar sesion');
});

it('labels a relation on a detail page too', function () {
    $blocks = $this->manifest['pages'][0]['blocks'];

    $data = app(BlockDataResolver::class)->resolve(
        $this->appModel,
        [$blocks[1]],
        $this->manifest,
        ['params' => ['id' => $this->reply->id]],
    );

    expect($data['blk_lbl_detail01']['record']['labels']['ticket'])
        ->toBe('No puedo iniciar sesion');
});

it('says nothing rather than guessing when the relation is empty', function () {
    $orphan = app(RecordWriteService::class)->create(
        $this->appModel,
        $this->manifest,
        'obj_lbl_replies1',
        ['mensaje' => 'Sin ticket'],
        $this->user,
    );

    $data = app(BlockDataResolver::class)->resolve(
        $this->appModel,
        [$this->manifest['pages'][0]['blocks'][0]],
        $this->manifest,
        [],
    );

    $row = collect($data['blk_lbl_table001']['rows'])->firstWhere('id', $orphan->id);

    expect($row['labels']['ticket'] ?? null)->toBeNull();
});

it('leaves a has-many alone — many records have no single label', function () {
    // The ticket side of the same relation. Expanding it would mean choosing
    // one child to stand for all of them, which is not a label but a lie.
    $blocks = [[
        'id' => 'blk_lbl_tickets1',
        'type' => 'table',
        'data_source' => ['object_id' => 'obj_lbl_tickets1'],
        'columns' => [['id' => 'col_lbl_replies', 'field_id' => 'fld_lbl_replies1']],
    ]];

    $data = app(BlockDataResolver::class)->resolve(
        $this->appModel,
        $blocks,
        $this->manifest,
        [],
    );

    expect($data['blk_lbl_tickets1']['rows'][0]['labels'] ?? null)->toBeNull();
});
