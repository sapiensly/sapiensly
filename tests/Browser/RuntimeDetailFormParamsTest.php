<?php

use App\Models\App;
use App\Models\Record;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * The master-detail write path, driven in a real browser.
 *
 * A detail page addresses its record through the URL query — `?id=…` — and every
 * scaffolded one carries an "add a child" modal whose on_submit writes that same
 * {{params.id}} into the child's parent field. The form block used to forward
 * ONLY the params injected by open_modal, so on a detail page that token
 * resolved to nothing and each child was saved with a NULL parent while the UI
 * reported success — an orphan nobody notices until the related list stays
 * empty. Nothing below the browser catches it: the endpoint does the right
 * thing with the params it is given, and the bug was in which params were sent.
 *
 * The second assertion covers the other half of the same submit. `blockData` is
 * a DEFERRED prop, so the `refresh` that closes the sequence must ask for it by
 * name; a plain reload came back without it and the page fell to its empty
 * state ("No record selected.") until a full navigation.
 */
function detailPageApp(): array
{
    $user = mcpMember($org = mcpOrg());
    test()->actingAs($user);

    $ticketObj = 'obj_'.strtolower((string) Str::ulid());
    $replyObj = 'obj_'.strtolower((string) Str::ulid());
    $asunto = 'fld_'.strtolower((string) Str::ulid());
    $mensaje = 'fld_'.strtolower((string) Str::ulid());
    $repliesRel = 'fld_'.strtolower((string) Str::ulid());
    $ticketRel = 'fld_'.strtolower((string) Str::ulid());

    $app = App::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'slug' => 'detail_params',
    ]);

    app(AppManifestService::class)->createVersion($app, [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'detail_params',
        'name' => 'Detail params',
        'version' => 1,
        'objects' => [
            [
                'id' => $ticketObj,
                'slug' => 'tickets',
                'name' => 'Ticket',
                'fields' => [
                    ['id' => $asunto, 'slug' => 'asunto', 'name' => 'Asunto', 'type' => 'string'],
                    [
                        'id' => $repliesRel,
                        'slug' => 'respuestas',
                        'name' => 'Respuestas',
                        'type' => 'relation',
                        'target_object_id' => $replyObj,
                        'cardinality' => 'one_to_many',
                        'inverse_field_id' => $ticketRel,
                    ],
                ],
            ],
            [
                'id' => $replyObj,
                'slug' => 'respuestas',
                'name' => 'Respuesta',
                'fields' => [
                    ['id' => $mensaje, 'slug' => 'mensaje', 'name' => 'Mensaje', 'type' => 'string'],
                    [
                        'id' => $ticketRel,
                        'slug' => 'ticket',
                        'name' => 'Ticket',
                        'type' => 'relation',
                        'target_object_id' => $ticketObj,
                        'cardinality' => 'many_to_one',
                        'inverse_field_id' => $repliesRel,
                        'on_delete' => 'set_null',
                    ],
                ],
            ],
        ],
        'pages' => [[
            'id' => 'pag_'.strtolower((string) Str::ulid()),
            'slug' => 'tickets_detail',
            'path' => '/tickets_detail',
            'name' => 'Ticket',
            'blocks' => [
                [
                    'id' => 'blk_detail',
                    'type' => 'record_detail',
                    'label' => 'Ticket',
                    'object_id' => $ticketObj,
                    'record_id_expression' => '{{params.id}}',
                    'fields' => [['field_id' => $asunto]],
                ],
                [
                    'id' => 'blk_modal',
                    'type' => 'modal',
                    'title' => 'Formulario de respuesta',
                    'blocks' => [[
                        'id' => 'blk_form',
                        'type' => 'form',
                        'mode' => 'create',
                        'object_id' => $replyObj,
                        'fields' => [['field_id' => $mensaje]],
                        'submit_label' => 'Guardar',
                        'on_submit' => [
                            [
                                'type' => 'create_record',
                                'object_id' => $replyObj,
                                'values' => ['mensaje' => '{{form.mensaje}}', 'ticket' => '{{params.id}}'],
                            ],
                            ['type' => 'close_modal'],
                            ['type' => 'refresh'],
                        ],
                    ]],
                ],
                [
                    'id' => 'blk_button',
                    'type' => 'button',
                    'label' => 'Agregar Respuesta',
                    'variant' => 'primary',
                    'on_click' => [['type' => 'open_modal', 'modal_block_id' => 'blk_modal']],
                ],
                [
                    'id' => 'blk_related',
                    'type' => 'related_list',
                    'object_id' => $replyObj,
                    'parent_id_expression' => '{{params.id}}',
                    'via_relation_field_id' => $ticketRel,
                    'columns' => [['field_id' => $mensaje]],
                ],
            ],
        ]],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ], $user);

    $ticket = Record::create([
        'app_id' => $app->id,
        'object_definition_id' => $ticketObj,
        'data' => ['asunto' => 'No puedo iniciar sesion'],
    ]);

    return ['app' => $app, 'ticket' => $ticket, 'reply_object' => $replyObj];
}

it('links a child created from a detail page to the record in the URL', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    ['app' => $app, 'ticket' => $ticket, 'reply_object' => $replyObject] = detailPageApp();

    visit("/r/{$app->slug}/tickets_detail?id={$ticket->id}")->on()->macbookAir()
        ->assertNoJavaScriptErrors()
        ->assertSee('No puedo iniciar sesion')
        ->click('Agregar Respuesta')
        ->type('#form_blk_form_mensaje', 'Ya reiniciamos tu contrasena')
        ->click('Guardar')
        // The related list is fed by parent_id_expression, so seeing the reply
        // here is the same claim as the assertion below — made through the UI.
        ->assertSee('Ya reiniciamos tu contrasena');

    $reply = Record::query()
        ->where('object_definition_id', $replyObject)
        ->sole();

    expect($reply->data['ticket'])->toBe($ticket->id);
});

it('keeps the detail record on screen after the post-submit refresh', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    ['app' => $app, 'ticket' => $ticket] = detailPageApp();

    visit("/r/{$app->slug}/tickets_detail?id={$ticket->id}")->on()->macbookAir()
        ->click('Agregar Respuesta')
        ->type('#form_blk_form_mensaje', 'Primera respuesta')
        ->click('Guardar')
        // `refresh` must re-request the deferred blockData: without it the
        // record_detail block loses its data and renders "No record selected."
        ->assertSee('No puedo iniciar sesion')
        ->assertDontSee('No record selected.');
});
