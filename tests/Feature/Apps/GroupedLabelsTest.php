<?php

use App\Models\App;
use App\Models\AppVersion;
use App\Models\Record;
use App\Models\User;
use App\Services\Apps\AppAccessResolver;
use App\Services\Records\RecordQueryService;
use App\Services\Records\RecordValidationException;
use Illuminate\Support\Str;

/**
 * A chart's axis, when what it groups by is a record.
 *
 * Grouping by a relation groups by the STORED VALUE, which is an id — so
 * "average score per question" came out as `rec_01kz4pne…` along the axis: a
 * correct answer to a question nobody can read. Found by building a survey
 * system on the platform and looking at the results screen.
 *
 * The id stays in `group` (charts drill through on it); the name arrives
 * beside it.
 */
function labelledApp(User $owner): array
{
    $app = App::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $owner->organization_id,
        'slug' => 'grp_'.Str::lower(Str::random(6)),
        'name' => 'Encuestas',
    ]);

    $manifest = [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => $app->slug,
        'name' => 'Encuestas',
        'version' => 1,
        'settings' => ['default_locale' => 'es-MX'],
        'objects' => [
            [
                'id' => 'obj_pregunta0001',
                'name' => 'Preguntas',
                'slug' => 'pregunta',
                'primary_display_field_id' => 'fld_texto000001',
                'fields' => [
                    ['id' => 'fld_texto000001', 'name' => 'Texto', 'slug' => 'texto', 'type' => 'string'],
                ],
            ],
            [
                'id' => 'obj_respuesta001',
                'name' => 'Respuestas',
                'slug' => 'respuesta',
                'fields' => [
                    ['id' => 'fld_valor000001', 'name' => 'Valor', 'slug' => 'valor', 'type' => 'number'],
                    [
                        'id' => 'fld_pregunta0001', 'name' => 'Pregunta', 'slug' => 'pregunta',
                        'type' => 'relation', 'cardinality' => 'many_to_one',
                        'target_object_id' => 'obj_pregunta0001',
                    ],
                ],
            ],
        ],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ];

    $version = AppVersion::create([
        'id' => 'ver_'.strtolower((string) Str::ulid()),
        'app_id' => $app->id,
        'version_number' => 1,
        'created_by' => $owner->id,
        'manifest' => $manifest,
    ]);

    $app->update(['current_version_id' => $version->id]);

    return [$app->fresh(), $manifest];
}

it('names the groups when a group is a record', function () {
    $owner = User::factory()->create();
    [$app, $manifest] = labelledApp($owner);

    $pregunta = Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_pregunta0001',
        'organization_id' => $app->organization_id,
        'data' => ['texto' => '¿Recomendarías esta empresa?'],
    ]);

    foreach ([4, 5] as $valor) {
        Record::create([
            'app_id' => $app->id,
            'object_definition_id' => 'obj_respuesta001',
            'organization_id' => $app->organization_id,
            'data' => ['valor' => $valor, 'pregunta' => $pregunta->id],
        ]);
    }

    $groups = app(RecordQueryService::class)->groupedAggregate(
        $app,
        ['object_id' => 'obj_respuesta001'],
        'avg',
        'fld_valor000001',
        'fld_pregunta0001',
        null,
        $manifest,
    );

    expect($groups)->toHaveCount(1)
        // The id stays: a chart drills through on it.
        ->and($groups[0]['group'])->toBe($pregunta->id)
        ->and($groups[0]['group_label'])->toBe('¿Recomendarías esta empresa?')
        ->and($groups[0]['value'])->toBe(4.5);
});

it('sends no label for the kinds of group that already read as themselves', function () {
    // A single_select or a date needs nothing from the server — the block has
    // the option list and the bucket. Sending a label for those would be a
    // query bought for nothing.
    $owner = User::factory()->create();
    [$app, $manifest] = labelledApp($owner);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_respuesta001',
        'organization_id' => $app->organization_id,
        'data' => ['valor' => 3],
    ]);

    $groups = app(RecordQueryService::class)->groupedAggregate(
        $app,
        ['object_id' => 'obj_respuesta001'],
        'count',
        null,
        'fld_valor000001',
        null,
        $manifest,
    );

    expect($groups[0])->not->toHaveKey('group_label');
});

it('does not name a record the reader could not open', function () {
    // The lookup runs through the same scope and access filter as any other
    // read: a name must not arrive for a row a direct read would hide.
    $owner = User::factory()->create();
    [$app, $manifest] = labelledApp($owner);

    $pregunta = Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_pregunta0001',
        'organization_id' => $app->organization_id,
        'data' => ['texto' => 'Secreta'],
    ]);

    Record::create([
        'app_id' => $app->id,
        'object_definition_id' => 'obj_respuesta001',
        'organization_id' => $app->organization_id,
        'data' => ['valor' => 1, 'pregunta' => $pregunta->id],
    ]);

    // A role whose row_filter excludes every question.
    $manifest['permissions'] = [
        'roles' => [['id' => 'rol_lector0001', 'slug' => 'lector', 'name' => 'Lector', 'is_default' => true]],
        'object_policies' => [
            [
                'object_id' => 'obj_pregunta0001',
                'role_id' => 'rol_lector0001',
                'actions' => ['read'],
                'row_filter' => ['op' => 'eq', 'field_id' => 'fld_texto000001', 'value' => 'nada'],
            ],
            ['object_id' => 'obj_respuesta001', 'role_id' => 'rol_lector0001', 'actions' => ['read']],
        ],
    ];

    $access = app(AppAccessResolver::class)->resolve($app, $manifest, null, 'lector');

    $groups = app(RecordQueryService::class)->groupedAggregate(
        $app,
        ['object_id' => 'obj_respuesta001'],
        'count',
        null,
        'fld_pregunta0001',
        null,
        $manifest,
        ['__access' => $access],
    );

    expect($groups[0]['group'])->toBe($pregunta->id)
        ->and($groups[0])->not->toHaveKey('group_label');
});

it('says which field a rejected write failed on', function () {
    // "Record validation failed for 1 field(s)" is true, useless, and the exact
    // text that reaches somebody debugging a workflow — where the errors array
    // is nowhere they can see it. Finding out which field meant reproducing the
    // write by hand somewhere the payload was visible.
    $e = new RecordValidationException(['opcion_seleccionada' => ['Opción seleccionada is required.']]);

    expect($e->getMessage())->toContain('opcion_seleccionada')
        ->and($e->getMessage())->toContain('is required')
        ->and($e->errors)->toHaveKey('opcion_seleccionada');
});

it('names the first few and counts the rest', function () {
    $e = new RecordValidationException([
        'a' => ['A is required.'], 'b' => ['B is required.'],
        'c' => ['C is required.'], 'd' => ['D is required.'],
        'e' => ['E is required.'], 'f' => ['F is required.'],
    ]);

    expect($e->getMessage())->toContain('a: A is required.')
        ->and($e->getMessage())->toContain('and 2 more field(s)')
        // Not a wall of text where a message has to be read at a glance.
        ->and(strlen($e->getMessage()))->toBeLessThan(200);
});
