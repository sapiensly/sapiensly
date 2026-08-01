<?php

use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Records\BlockDataResolver;
use App\Services\Records\RecordWriteService;
use Illuminate\Support\Str;

/**
 * What an EDIT form loads, and what it is allowed to write.
 *
 * `mode: "edit"` used to be a word nothing read: the form never loaded the
 * record, and since a submit posted every input it drew, saving one wrote that
 * blankness over every field the user had not retyped. Two rules now hold it
 * together, and both live on the server, which is why they are asserted here:
 *
 *  - the record's stored values reach the form as its defaults, whenever the
 *    block's id is resolvable when the page renders;
 *  - a `{{form.x}}` whose field was not submitted is DROPPED from the write
 *    rather than resolved to empty — the half that makes "send only what
 *    changed" mean anything, since the values map names every field the form
 *    can touch regardless of what the user did.
 *
 * @return array<string, mixed>
 */
function editFormManifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'edit_forms',
        'name' => 'Edit forms',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_edit_tickets1',
            'slug' => 'tickets',
            'name' => 'Ticket',
            'fields' => [
                ['id' => 'fld_edit_asunto1', 'slug' => 'asunto', 'name' => 'Asunto', 'type' => 'string', 'required' => true],
                ['id' => 'fld_edit_nota0001', 'slug' => 'nota', 'name' => 'Nota', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'id' => 'pag_edit_00000001',
            'slug' => 'ticket',
            'path' => '/ticket',
            'name' => 'Ticket',
            'blocks' => [[
                'id' => 'blk_edit_00000001',
                'type' => 'form',
                'object_id' => 'obj_edit_tickets1',
                'mode' => 'edit',
                'record_id_expression' => '{{params.id}}',
                'fields' => [['field_id' => 'fld_edit_asunto1'], ['field_id' => 'fld_edit_nota0001']],
                'on_submit' => [[
                    'type' => 'update_record',
                    'object_id' => 'obj_edit_tickets1',
                    'record_id_expression' => '{{params.id}}',
                    'values' => ['asunto' => '{{form.asunto}}', 'nota' => '{{form.nota}}'],
                ]],
            ]],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'edit_forms',
    ]);
    $this->manifest = editFormManifest($this->appModel->id);
    app(AppManifestService::class)->createVersion($this->appModel, $this->manifest, $this->user);

    $this->record = app(RecordWriteService::class)->create(
        $this->appModel,
        $this->manifest,
        'obj_edit_tickets1',
        ['asunto' => 'No puedo entrar', 'nota' => 'Reviso manana'],
        $this->user,
    );
});

it('hands an edit form the record it is editing', function () {
    $blocks = $this->manifest['pages'][0]['blocks'];

    $data = app(BlockDataResolver::class)->resolve(
        $this->appModel,
        $blocks,
        $this->manifest,
        ['params' => ['id' => $this->record->id]],
    );

    expect($data['blk_edit_00000001']['form']['defaults'])
        ->toEqual(['asunto' => 'No puedo entrar', 'nota' => 'Reviso manana']);
});

it('leaves an edit form empty when its record id is not knowable yet', function () {
    // A modal opened from a row learns its id on the click, so there is nothing
    // to resolve at render — the form is seeded in the browser instead, and the
    // server must not guess.
    $blocks = $this->manifest['pages'][0]['blocks'];
    $blocks[0]['record_id_expression'] = '{{params.record_id}}';

    $data = app(BlockDataResolver::class)->resolve(
        $this->appModel,
        $blocks,
        $this->manifest,
        ['params' => []],
    );

    expect($data['blk_edit_00000001'] ?? null)->toBeNull();
});

it('touches only the fields the submit actually carried', function () {
    $this->actingAs($this->user)->postJson('/r/edit_forms/actions', [
        'actions' => $this->manifest['pages'][0]['blocks'][0]['on_submit'],
        'form' => ['nota' => 'Ya quedo'],
        'params' => ['id' => $this->record->id],
    ])->assertOk();

    // `asunto` was never sent, so the values map's {{form.asunto}} is dropped
    // and the stored value stands. Resolving it to '' instead would have
    // erased it — or, since it is required, rejected a save the user could see
    // filled in on screen.
    expect($this->record->fresh()->data)
        ->toEqual(['asunto' => 'No puedo entrar', 'nota' => 'Ya quedo']);
});

it('still writes a field the user deliberately cleared', function () {
    // Emptying a field is a change, so it travels — the key is present with an
    // empty value, which must not be confused with "not submitted".
    $this->actingAs($this->user)->postJson('/r/edit_forms/actions', [
        'actions' => $this->manifest['pages'][0]['blocks'][0]['on_submit'],
        'form' => ['nota' => ''],
        'params' => ['id' => $this->record->id],
    ])->assertOk();

    // The write path stores a cleared string as null, so "emptied" reads as
    // null here — the point is that it changed, and that `asunto` did not.
    expect($this->record->fresh()->data['nota'])->toBeNull()
        ->and($this->record->fresh()->data['asunto'])->toBe('No puedo entrar');
});

it('still rejects a required field the submit sent empty', function () {
    // Dropping unsent keys must not become a way to smuggle a blank past
    // validation: sending the field and clearing it is still a rejection.
    $this->actingAs($this->user)->postJson('/r/edit_forms/actions', [
        'actions' => $this->manifest['pages'][0]['blocks'][0]['on_submit'],
        'form' => ['asunto' => ''],
        'params' => ['id' => $this->record->id],
    ])->assertStatus(422);

    expect($this->record->fresh()->data['asunto'])->toBe('No puedo entrar');
});
