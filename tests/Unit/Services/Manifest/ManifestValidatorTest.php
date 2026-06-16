<?php

use App\Services\Manifest\ManifestValidator;
use Illuminate\Support\Str;

function ulid(): string
{
    return strtolower((string) Str::ulid());
}

function id(string $prefix): string
{
    return $prefix.'_'.ulid();
}

function baseManifest(array $overrides = []): array
{
    $appId = id('app');
    $objId = id('obj');
    $fldNombre = id('fld');
    $rolAdmin = id('rol');

    return array_replace_recursive([
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_crm',
        'name' => 'Mini CRM',
        'version' => 1,
        'objects' => [
            [
                'id' => $objId,
                'slug' => 'clientes',
                'name' => 'Cliente',
                'primary_display_field_id' => $fldNombre,
                'fields' => [
                    ['id' => $fldNombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ],
            ],
        ],
        'pages' => [],
        'permissions' => [
            'roles' => [
                ['id' => $rolAdmin, 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true],
            ],
        ],
    ], $overrides);
}

it('accepts a minimal valid manifest', function () {
    $result = (new ManifestValidator)->validate(baseManifest());

    expect($result->valid)->toBeTrue()
        ->and($result->errors)->toBe([]);
});

it('rejects missing required top-level fields', function () {
    $manifest = baseManifest();
    unset($manifest['permissions']);

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeFalse();
});

it('rejects an object missing slug (schema layer)', function () {
    $manifest = baseManifest();
    unset($manifest['objects'][0]['slug']);

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeFalse()
        ->and($result->errors[0]->code)->toBe('schema');
});

it('rejects additional properties on a field', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][0]['nonsense_property'] = true;

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeFalse()
        ->and($result->errors[0]->code)->toBe('schema');
});

it('enriches an unknown block type error with the schema description hint', function () {
    $manifest = baseManifest();
    $manifest['pages'][] = [
        'id' => id('pag'),
        'slug' => 'home',
        'name' => 'Home',
        'path' => '/home',
        'blocks' => [
            ['id' => id('blk'), 'type' => 'nonexistent_block'],
        ],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('message')->implode("\n"))
        ->toContain('list_available_components'); // the schema hint reached the error message
});

it('rejects duplicate object slugs', function () {
    $manifest = baseManifest();
    $manifest['objects'][] = [
        'id' => id('obj'),
        'slug' => 'clientes',
        'name' => 'Otro Cliente',
        'fields' => [
            ['id' => id('fld'), 'slug' => 'a', 'name' => 'A', 'type' => 'string'],
        ],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('duplicate_slug');
});

it('rejects duplicate field slugs within the same object', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'nombre', 'name' => 'Otro Nombre', 'type' => 'string',
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('duplicate_slug');
});

it('rejects primary_display_field_id that does not match any field', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['primary_display_field_id'] = id('fld');

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects relation field with unknown target_object_id', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'),
        'slug' => 'rel',
        'name' => 'Rel',
        'type' => 'relation',
        'target_object_id' => id('obj'),
        'cardinality' => 'many_to_one',
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects incompatible inverse cardinality on relation pair', function () {
    $objA = id('obj');
    $objB = id('obj');
    $fldAtoB = id('fld');
    $fldBtoA = id('fld');

    $manifest = baseManifest();
    $manifest['objects'] = [
        [
            'id' => $objA,
            'slug' => 'a',
            'name' => 'A',
            'fields' => [
                ['id' => id('fld'), 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                [
                    'id' => $fldAtoB,
                    'slug' => 'to_b',
                    'name' => 'To B',
                    'type' => 'relation',
                    'target_object_id' => $objB,
                    'cardinality' => 'one_to_many',
                    'inverse_field_id' => $fldBtoA,
                ],
            ],
        ],
        [
            'id' => $objB,
            'slug' => 'b',
            'name' => 'B',
            'fields' => [
                ['id' => id('fld'), 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                [
                    'id' => $fldBtoA,
                    'slug' => 'to_a',
                    'name' => 'To A',
                    'type' => 'relation',
                    'target_object_id' => $objA,
                    'cardinality' => 'one_to_many',
                ],
            ],
        ],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_cardinality');
});

it('rejects table block columns whose field_id does not belong to the data_source object', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'columns' => [
                ['id' => id('col'), 'field_id' => id('fld')],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects stat block sum aggregation over a non-numeric field', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'stat',
            'label' => 'Total',
            'query' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'sum',
            'field_id' => $manifest['objects'][0]['fields'][0]['id'], // string field
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_type');
});

it('rejects stat block sum aggregation without field_id', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'stat',
            'label' => 'Total',
            'query' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'sum',
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('missing_required');
});

it('accepts stat block count aggregation without field_id', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'stat',
            'label' => 'Total',
            'query' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'count',
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue();
});

it('rejects filter expression referencing field_id not in scope', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $manifest['objects'][0]['id'],
                'filter' => [
                    'op' => 'eq',
                    'field_id' => id('fld'),
                    'value' => 'x',
                ],
            ],
            'columns' => [
                ['id' => id('col'), 'field_id' => $manifest['objects'][0]['fields'][0]['id']],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects malformed value_expression braces', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $manifest['objects'][0]['id'],
                'filter' => [
                    'op' => 'eq',
                    'field_id' => $manifest['objects'][0]['fields'][0]['id'],
                    'value_expression' => '{{current_user.id', // unbalanced
                ],
            ],
            'columns' => [
                ['id' => id('col'), 'field_id' => $manifest['objects'][0]['fields'][0]['id']],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('malformed_expression');
});

it('rejects a value_expression with balanced braces but malformed grammar', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $manifest['objects'][0]['id'],
                'filter' => [
                    'op' => 'eq',
                    'field_id' => $manifest['objects'][0]['fields'][0]['id'],
                    'value_expression' => '{{count(current_user.id}}', // unbalanced paren
                ],
            ],
            'columns' => [
                ['id' => id('col'), 'field_id' => $manifest['objects'][0]['fields'][0]['id']],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('malformed_expression');
});

it('rejects a form field visible_if referencing an unknown field_id', function () {
    $manifest = baseManifest();
    $fieldId = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'form',
            'object_id' => $manifest['objects'][0]['id'],
            'mode' => 'create',
            'fields' => [[
                'field_id' => $fieldId,
                'visible_if' => ['field_id' => 'fld_does_not_exist', 'op' => 'eq', 'value' => 'x'],
            ]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('accepts a form field with a valid visible_if / required_if condition', function () {
    $manifest = baseManifest();
    $fieldId = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'form',
            'object_id' => $manifest['objects'][0]['id'],
            'mode' => 'create',
            'fields' => [[
                'field_id' => $fieldId,
                'visible_if' => ['field_id' => $fieldId, 'op' => 'is_not_null'],
                'required_if' => ['field_id' => $fieldId, 'op' => 'eq', 'value' => 'x'],
            ]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue();
});

it('rejects a value_expression that calls an unknown function', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $manifest['objects'][0]['id'],
                'filter' => [
                    'op' => 'eq',
                    'field_id' => $manifest['objects'][0]['fields'][0]['id'],
                    'value_expression' => '{{sumar(form.a, form.b)}}', // not in catalog
                ],
            ],
            'columns' => [
                ['id' => id('col'), 'field_id' => $manifest['objects'][0]['fields'][0]['id']],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    $error = collect($result->errors)->firstWhere('code', 'unknown_function');
    expect($error)->not->toBeNull()
        ->and($error->message)->toContain('sumar()')
        ->and($error->message)->toContain('script.run');
});

it('rejects a default_expression that uses a JS-style method call', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'form',
            'object_id' => $manifest['objects'][0]['id'],
            'mode' => 'create',
            'fields' => [[
                'field_id' => $manifest['objects'][0]['fields'][0]['id'],
                'default_expression' => '{{Math.random()}}',
            ]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unknown_function');
});

it('accepts a value_expression that uses a known catalog function', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'),
        'slug' => 'p',
        'name' => 'P',
        'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $manifest['objects'][0]['id'],
                'filter' => [
                    'op' => 'gte',
                    'field_id' => $manifest['objects'][0]['fields'][0]['id'],
                    'value_expression' => '{{today()}}',
                ],
            ],
            'columns' => [
                ['id' => id('col'), 'field_id' => $manifest['objects'][0]['fields'][0]['id']],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue();
});

it('warns (without failing) when a form on_submit does nothing but show a toast', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'form',
            'object_id' => $manifest['objects'][0]['id'], 'mode' => 'create',
            'on_submit' => [['type' => 'show_toast', 'level' => 'info', 'message' => 'Calculando...']],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue()
        ->and(collect($result->warnings)->pluck('code'))->toContain('incomplete_action');
});

it('does not warn when a form on_submit creates a record', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'form',
            'object_id' => $obj['id'], 'mode' => 'create',
            'on_submit' => [
                ['type' => 'create_record', 'object_id' => $obj['id'], 'values' => [$obj['fields'][0]['slug'] => 'x']],
                ['type' => 'refresh'],
            ],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue()
        ->and(collect($result->warnings)->pluck('code'))->not->toContain('incomplete_action');
});

it('warns when a button on_click only shows a toast', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Generar',
            'on_click' => [['type' => 'show_toast', 'level' => 'info', 'message' => 'Generando...']],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeTrue()
        ->and(collect($result->warnings)->pluck('code'))->toContain('no_effect');
});

it('does not warn when a button opens a modal', function () {
    $manifest = baseManifest();
    $modalId = id('blk');
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [
            ['id' => id('blk'), 'type' => 'button', 'label' => 'Nuevo', 'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]]],
            ['id' => $modalId, 'type' => 'modal', 'title' => 'M', 'blocks' => []],
        ],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->warnings)->pluck('code'))->not->toContain('no_effect');
});

it('accepts a hero block, a coloured-background section and an external image', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [
            [
                'id' => id('blk'), 'type' => 'hero',
                'title' => 'Cambio Climático',
                'subtitle' => 'Actúa ahora',
                'background_image' => 'https://source.unsplash.com/1600x900/?climate,earth',
                'overlay' => true, 'align' => 'center',
            ],
            [
                'id' => id('blk'), 'type' => 'container', 'direction' => 'column',
                'style' => ['padding' => 'lg', 'background' => '#0F172A'],
                'blocks' => [
                    ['id' => id('blk'), 'type' => 'heading', 'content' => 'Causas', 'level' => 2],
                    ['id' => id('blk'), 'type' => 'image', 'src' => 'https://picsum.photos/1600/900', 'alt' => 'Planeta'],
                ],
            ],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts style.color and style.max_width on a section', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'container', 'direction' => 'column',
            'style' => ['padding' => 'lg', 'background' => '#1e293b', 'color' => '#f8fafc', 'max_width' => 'md'],
            'blocks' => [
                ['id' => id('blk'), 'type' => 'heading', 'content' => 'Actúa ahora', 'level' => 2],
            ],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects an invalid style.max_width value', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'heading', 'content' => 'X', 'level' => 2,
            'style' => ['max_width' => 'enormous'],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('accepts site settings (accent, font, brand, footer) and the marketing blocks', function () {
    $manifest = baseManifest();
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], [
        'theme' => 'light',
        'accent' => '#0EA5E9',
        'font' => 'serif',
        'brand' => ['name' => 'EcoSite', 'cta' => ['label' => 'Únete', 'href' => '#cta']],
        'footer' => ['text' => '© EcoSite', 'links' => [['label' => 'Privacidad', 'href' => '#']]],
    ]);
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [
            ['id' => id('blk'), 'type' => 'stat_band', 'items' => [['value' => '1.1°C', 'label' => 'Aumento']]],
            ['id' => id('blk'), 'type' => 'testimonials', 'columns' => 2, 'items' => [['quote' => 'Genial', 'author' => 'Ana', 'role' => 'CEO']]],
            ['id' => id('blk'), 'type' => 'faq', 'items' => [['question' => '¿Qué?', 'answer' => 'Esto.']]],
            ['id' => id('blk'), 'type' => 'pricing', 'columns' => 2, 'tiers' => [
                ['name' => 'Free', 'price' => '$0', 'features' => ['A', 'B']],
                ['name' => 'Pro', 'price' => '$29', 'period' => '/mes', 'featured' => true, 'features' => ['Todo'], 'cta' => ['label' => 'Comprar', 'on_click' => [['type' => 'navigate', 'to' => '/checkout']]]],
            ]],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects an invalid accent colour', function () {
    $manifest = baseManifest();
    $manifest['settings'] = array_merge($manifest['settings'] ?? [], ['accent' => 'blue']);
    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('validates a pricing tier CTA action sequence', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [['id' => id('blk'), 'type' => 'pricing', 'tiers' => [
            ['name' => 'Pro', 'price' => '$9', 'cta' => ['label' => 'X', 'on_click' => [['type' => 'open_modal', 'modal_block_id' => 'blk_does_not_exist']]]],
        ]]],
    ]];
    expect(collect((new ManifestValidator)->validate($manifest)->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('accepts dashboard blocks: insight card, stat with compare/trend, radar & scatter charts', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $numField = collect($obj['fields'])->firstWhere('type', 'number') ?? $obj['fields'][0];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'dash', 'name' => 'Dash', 'path' => '/dash',
        'blocks' => [
            ['id' => id('blk'), 'type' => 'insight', 'variant' => 'recommendation', 'title' => 'Sube precios', 'body' => 'El margen lo permite.', 'metric' => '+12%'],
            ['id' => id('blk'), 'type' => 'stat', 'label' => 'Ventas', 'query' => ['object_id' => $obj['id']], 'aggregation' => 'count', 'compare' => ['object_id' => $obj['id']], 'delta_good' => 'up', 'icon' => '📈'],
            ['id' => id('blk'), 'type' => 'chart', 'chart_type' => 'radar', 'data_source' => ['object_id' => $obj['id']], 'aggregation' => 'count', 'group_by_field_id' => $obj['fields'][0]['id']],
            ['id' => id('blk'), 'type' => 'chart', 'chart_type' => 'scatter', 'data_source' => ['object_id' => $obj['id']], 'aggregation' => 'count', 'x_field_id' => $numField['id'], 'y_field_id' => $numField['id']],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts treemap chart, word_cloud and flow blocks', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'd', 'name' => 'D', 'path' => '/d',
        'blocks' => [
            ['id' => id('blk'), 'type' => 'chart', 'chart_type' => 'treemap', 'data_source' => ['object_id' => $obj['id']], 'aggregation' => 'count', 'group_by_field_id' => $obj['fields'][0]['id']],
            ['id' => id('blk'), 'type' => 'word_cloud', 'data_source' => ['object_id' => $obj['id']], 'field_id' => $obj['fields'][0]['id'], 'max_words' => 30],
            ['id' => id('blk'), 'type' => 'flow', 'direction' => 'row', 'steps' => [
                ['label' => 'Captura', 'icon' => '📝'],
                ['label' => 'Procesa', 'description' => 'Valida y agrega'],
                ['label' => 'Reporta'],
            ]],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a flow with fewer than 2 steps', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'd', 'name' => 'D', 'path' => '/d',
        'blocks' => [['id' => id('blk'), 'type' => 'flow', 'steps' => [['label' => 'Solo uno']]]],
    ]];
    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('rejects an unknown chart_type', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'd', 'name' => 'D', 'path' => '/d',
        'blocks' => [['id' => id('blk'), 'type' => 'chart', 'chart_type' => 'sankey', 'data_source' => ['object_id' => $obj['id']], 'aggregation' => 'count']],
    ]];
    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('accepts a heading size override and rejects an invalid one', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [['id' => id('blk'), 'type' => 'heading', 'content' => 'Hola', 'level' => 2, 'size' => 'display']],
    ]];
    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();

    $manifest['pages'][0]['blocks'][0]['size'] = 'gigante';
    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('accepts a full-bleed gradient section, a feature_grid and a cta', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [
            [
                'id' => id('blk'), 'type' => 'container', 'direction' => 'column',
                'style' => ['padding' => 'lg', 'full_bleed' => true, 'max_width' => 'md', 'gradient' => ['from' => '#0ea5e9', 'to' => '#1e3a8a', 'direction' => 'to-br']],
                'blocks' => [
                    ['id' => id('blk'), 'type' => 'heading', 'content' => 'Beneficios', 'level' => 2],
                    ['id' => id('blk'), 'type' => 'feature_grid', 'columns' => 3, 'items' => [
                        ['icon' => '🌍', 'title' => 'Planeta', 'description' => 'Cuida la tierra'],
                        ['icon' => '⚡', 'title' => 'Energía', 'description' => 'Renovable'],
                        ['icon' => '🌱', 'title' => 'Futuro', 'description' => 'Sostenible'],
                    ]],
                ],
            ],
            ['id' => id('blk'), 'type' => 'cta', 'title' => 'Actúa ahora', 'subtitle' => 'Únete', 'style' => ['full_bleed' => true, 'padding' => 'lg', 'background' => '#0f172a']],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a gradient missing its `to` colour', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'heading', 'content' => 'X', 'level' => 2,
            'style' => ['gradient' => ['from' => '#0ea5e9']],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('validates a cta button action sequence (rejects an unknown modal target)', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'cta', 'title' => 'Go',
            'button' => ['label' => 'Abrir', 'on_click' => [['type' => 'open_modal', 'modal_block_id' => 'blk_does_not_exist']]],
        ]],
    ]];

    expect(collect((new ManifestValidator)->validate($manifest)->errors)->pluck('code'))
        ->toContain('unresolved_ref');
});

it('validates a hero CTA action sequence (rejects an unknown modal target)', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'home', 'name' => 'Home', 'path' => '/home',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'hero', 'title' => 'Hi',
            'cta' => ['label' => 'Abrir', 'on_click' => [['type' => 'open_modal', 'modal_block_id' => 'blk_does_not_exist']]],
        ]],
    ]];

    expect(collect((new ManifestValidator)->validate($manifest)->errors)->pluck('code'))
        ->toContain('unresolved_ref');
});

it('rejects duplicate page slugs and paths', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [
        ['id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p', 'blocks' => []],
        ['id' => id('pag'), 'slug' => 'p', 'name' => 'P2', 'path' => '/p', 'blocks' => []],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    $codes = collect($result->errors)->pluck('code');
    expect($codes)->toContain('duplicate_slug')
        ->and($codes)->toContain('duplicate_path');
});

it('rejects object_policy object_id not matching any object', function () {
    $manifest = baseManifest();
    $manifest['permissions']['object_policies'] = [
        [
            'object_id' => id('obj'),
            'role_id' => $manifest['permissions']['roles'][0]['id'],
            'actions' => ['read'],
        ],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects navigation page_id not matching any page', function () {
    $manifest = baseManifest();
    $manifest['navigation'] = [
        'items' => [
            ['id' => id('nav'), 'label' => 'Foo', 'page_id' => id('pag')],
        ],
    ];

    $result = (new ManifestValidator)->validate($manifest);

    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects bad color hex', function () {
    $manifest = baseManifest();
    $manifest['color'] = 'red';

    $result = (new ManifestValidator)->validate($manifest);

    expect($result->valid)->toBeFalse()
        ->and($result->errors[0]->code)->toBe('schema');
});

it('accepts a form block with valid object_id and field_ids', function () {
    $manifest = baseManifest();
    $objectId = $manifest['objects'][0]['id'];
    $fieldId = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'form',
            'object_id' => $objectId,
            'mode' => 'create',
            'fields' => [['field_id' => $fieldId]],
            'on_submit' => [['type' => 'create_record', 'object_id' => $objectId, 'values' => ['nombre' => '{{form.nombre}}']]],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a form block referencing an unknown object_id', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'form',
            'object_id' => id('obj'), 'mode' => 'create',
            'fields' => [],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects a form block with field_id that does not belong to its object', function () {
    $manifest = baseManifest();
    $objectId = $manifest['objects'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'form',
            'object_id' => $objectId, 'mode' => 'create',
            'fields' => [['field_id' => id('fld')]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects a form with mode=edit but no record_id_expression', function () {
    $manifest = baseManifest();
    $objectId = $manifest['objects'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'form',
            'object_id' => $objectId, 'mode' => 'edit',
            'fields' => [],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('missing_required');
});

it('rejects open_modal pointing to a modal not in the same page', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Open',
            'on_click' => [['type' => 'open_modal', 'modal_block_id' => id('blk')]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('accepts open_modal pointing to a modal declared in the same page', function () {
    $modalId = id('blk');
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [
            ['id' => id('blk'), 'type' => 'button', 'label' => 'Open',
                'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]]],
            ['id' => $modalId, 'type' => 'modal', 'title' => 'X', 'blocks' => []],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a create_record action with unknown object_id', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Add',
            'on_click' => [['type' => 'create_record', 'object_id' => id('obj'), 'values' => ['nombre' => 'x']]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('accepts an update_record action with valid object_id and record_id_expression', function () {
    $manifest = baseManifest();
    $objectId = $manifest['objects'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Save',
            'on_click' => [['type' => 'update_record', 'object_id' => $objectId, 'record_id_expression' => '{{params.id}}', 'values' => ['nombre' => 'x']]],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts a formula field with valid expression and return_type', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'total', 'name' => 'Total',
        'type' => 'formula', 'readonly' => true,
        'expression' => '{{nombre}}',
        'return_type' => 'string',
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('detects a formula cycle (a → b → a)', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'a', 'name' => 'A',
        'type' => 'formula', 'readonly' => true,
        'expression' => '{{b}}', 'return_type' => 'string',
    ];
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'b', 'name' => 'B',
        'type' => 'formula', 'readonly' => true,
        'expression' => '{{a}}', 'return_type' => 'string',
    ];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('formula_cycle');
});

it('rejects a lookup whose via_relation_field_id is not a relation field', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'looked', 'name' => 'Looked',
        'type' => 'lookup', 'readonly' => true,
        'via_relation_field_id' => $manifest['objects'][0]['fields'][0]['id'],
        'target_field_id' => id('fld'),
    ];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_type');
});

it('accepts a rollup with sum aggregator over a numeric target field', function () {
    $childMonto = id('fld');
    $manifest = baseManifest();
    $childObj = [
        'id' => id('obj'), 'slug' => 'ventas', 'name' => 'Venta',
        'fields' => [
            ['id' => id('fld'), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ['id' => $childMonto, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'],
        ],
    ];
    $manifest['objects'][] = $childObj;
    $relationFieldId = id('fld');
    $manifest['objects'][0]['fields'][] = [
        'id' => $relationFieldId, 'slug' => 'ventas', 'name' => 'Ventas',
        'type' => 'relation', 'target_object_id' => $childObj['id'], 'cardinality' => 'one_to_many',
    ];
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'total_ventas', 'name' => 'Total ventas',
        'type' => 'rollup', 'readonly' => true,
        'via_relation_field_id' => $relationFieldId,
        'target_field_id' => $childMonto,
        'aggregator' => 'sum',
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a rollup sum over a non-numeric field', function () {
    $childNombre = id('fld');
    $manifest = baseManifest();
    $childObj = [
        'id' => id('obj'), 'slug' => 'comentarios', 'name' => 'Comentario',
        'fields' => [
            ['id' => $childNombre, 'slug' => 'texto', 'name' => 'Texto', 'type' => 'string'],
        ],
    ];
    $manifest['objects'][] = $childObj;
    $relationFieldId = id('fld');
    $manifest['objects'][0]['fields'][] = [
        'id' => $relationFieldId, 'slug' => 'comentarios', 'name' => 'Comentarios',
        'type' => 'relation', 'target_object_id' => $childObj['id'], 'cardinality' => 'one_to_many',
    ];
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'sum_comentarios', 'name' => 'Sum',
        'type' => 'rollup', 'readonly' => true,
        'via_relation_field_id' => $relationFieldId,
        'target_field_id' => $childNombre,
        'aggregator' => 'sum',
    ];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_type');
});

it('accepts a rollup count without target_field_id', function () {
    $manifest = baseManifest();
    $childObj = [
        'id' => id('obj'), 'slug' => 'orders', 'name' => 'Order',
        'fields' => [['id' => id('fld'), 'slug' => 'name', 'name' => 'Name', 'type' => 'string']],
    ];
    $manifest['objects'][] = $childObj;
    $relationFieldId = id('fld');
    $manifest['objects'][0]['fields'][] = [
        'id' => $relationFieldId, 'slug' => 'orders', 'name' => 'Orders',
        'type' => 'relation', 'target_object_id' => $childObj['id'], 'cardinality' => 'one_to_many',
    ];
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'orders_count', 'name' => 'Orders count',
        'type' => 'rollup', 'readonly' => true,
        'via_relation_field_id' => $relationFieldId,
        'aggregator' => 'count',
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts a manual workflow with valid steps', function () {
    $manifest = baseManifest();
    $objectId = $manifest['objects'][0]['id'];
    $manifest['workflows'] = [[
        'id' => id('wkf'),
        'slug' => 'notify',
        'name' => 'Notify',
        'trigger' => ['type' => 'manual', 'label' => 'Run notify'],
        'steps' => [
            ['id' => id('stp'), 'type' => 'log', 'message' => 'starting'],
            ['id' => id('stp'), 'type' => 'record.create', 'object_id' => $objectId, 'values' => ['nombre' => 'x']],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts a workflow with a valid script.run step', function () {
    $manifest = baseManifest();
    $manifest['workflows'] = [[
        'id' => id('wkf'),
        'slug' => 'compute',
        'name' => 'Compute',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => id('stp'),
            'type' => 'script.run',
            'code' => 'return input.a + input.b;',
            'input' => ['a' => '{{trigger.a}}', 'b' => '{{trigger.b}}'],
            'timeout_ms' => 1500,
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a script.run step missing its code', function () {
    $manifest = baseManifest();
    $manifest['workflows'] = [[
        'id' => id('wkf'),
        'slug' => 'compute',
        'name' => 'Compute',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => id('stp'), 'type' => 'script.run', 'input' => ['a' => '{{trigger.a}}']]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('rejects a workflow expression referencing {{form.*}} (not available in workflows)', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $manifest['workflows'] = [[
        'id' => id('wkf'), 'slug' => 'calc', 'name' => 'Calc',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => id('stp'), 'type' => 'script.run',
            'code' => 'return {};',
            'input' => ['inicio' => '{{form.rango_inicio}}'], // form is a UI root, not workflow
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);

    $error = collect($result->errors)->firstWhere('code', 'invalid_context');
    expect($error)->not->toBeNull()
        ->and($error->message)->toContain('form')
        ->and($error->message)->toContain('trigger');
});

it('rejects {{params.*}} inside a workflow record.create value', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $manifest['workflows'] = [[
        'id' => id('wkf'), 'slug' => 'w', 'name' => 'W',
        'trigger' => ['type' => 'manual'],
        'steps' => [[
            'id' => id('stp'), 'type' => 'record.create', 'object_id' => $obj['id'],
            'values' => [$obj['fields'][0]['slug'] => '{{params.foo}}'],
        ]],
    ]];

    expect(collect((new ManifestValidator)->validate($manifest)->errors)->pluck('code'))
        ->toContain('invalid_context');
});

it('accepts a workflow that uses {{trigger.*}} and {{vars.*}}', function () {
    $manifest = baseManifest();
    $obj = $manifest['objects'][0];
    $calc = id('stp');
    $manifest['workflows'] = [[
        'id' => id('wkf'), 'slug' => 'w', 'name' => 'W',
        'trigger' => ['type' => 'manual'],
        'steps' => [
            ['id' => $calc, 'type' => 'script.run', 'output_variable' => 'calc',
                'code' => 'return {items: []};', 'input' => ['n' => '{{trigger.n}}']],
            ['id' => id('stp'), 'type' => 'foreach', 'items' => '{{vars.calc.items}}', 'steps' => [
                ['id' => id('stp'), 'type' => 'record.create', 'object_id' => $obj['id'],
                    'values' => [$obj['fields'][0]['slug'] => '{{vars.item}}']],
            ]],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a workflow trigger object_id that does not exist', function () {
    $manifest = baseManifest();
    $manifest['workflows'] = [[
        'id' => id('wkf'),
        'slug' => 'w',
        'name' => 'W',
        'trigger' => ['type' => 'record.created', 'object_id' => id('obj')],
        'steps' => [['id' => id('stp'), 'type' => 'log', 'message' => 'x']],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects a workflow step record.create with unknown object_id', function () {
    $manifest = baseManifest();
    $manifest['workflows'] = [[
        'id' => id('wkf'),
        'slug' => 'w',
        'name' => 'W',
        'trigger' => ['type' => 'manual'],
        'steps' => [
            ['id' => id('stp'), 'type' => 'record.create', 'object_id' => id('obj'), 'values' => ['nombre' => 'x']],
        ],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects a run_workflow action pointing to a workflow not declared', function () {
    $manifest = baseManifest();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Go',
            'on_click' => [['type' => 'run_workflow', 'workflow_id' => id('wkf')]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('accepts a run_workflow action when the workflow exists', function () {
    $wfId = id('wkf');
    $manifest = baseManifest();
    $manifest['workflows'] = [[
        'id' => $wfId, 'slug' => 'go', 'name' => 'Go',
        'trigger' => ['type' => 'manual'],
        'steps' => [['id' => id('stp'), 'type' => 'log', 'message' => 'x']],
    ]];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Go',
            'on_click' => [['type' => 'run_workflow', 'workflow_id' => $wfId]],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects malformed value_expression inside an action values map', function () {
    $manifest = baseManifest();
    $objectId = $manifest['objects'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'), 'type' => 'button', 'label' => 'Add',
            'on_click' => [['type' => 'create_record', 'object_id' => $objectId, 'values' => ['nombre' => '{{form.x']]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('malformed_expression');
});

/**
 * Build a manifest with one object that has a string field plus a numeric
 * field. Used by the visual-block tests below — most of them need both kinds.
 *
 * @return array{0: array<string, mixed>, 1: string, 2: string} [$manifest, $fldNombreId, $fldMontoId]
 */
function manifestWithNumericObject(): array
{
    $fldNombre = id('fld');
    $fldMonto = id('fld');
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'] = [
        ['id' => $fldNombre, 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
        ['id' => $fldMonto, 'slug' => 'monto', 'name' => 'Monto', 'type' => 'currency', 'currency_code' => 'MXN'],
    ];
    $manifest['objects'][0]['primary_display_field_id'] = $fldNombre;

    return [$manifest, $fldNombre, $fldMonto];
}

it('rejects a sparkline block that references an unknown y_field_id', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'sparkline',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'sum',
            'y_field_id' => id('fld'),
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect($result->valid)->toBeFalse()
        ->and(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects a chart with sum aggregation but no y_field_id', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'chart',
            'chart_type' => 'bar',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'sum',
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('missing_required');
});

it('rejects a chart whose y_field_id points at a non-numeric field', function () {
    [$manifest, $fldNombre] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'chart',
            'chart_type' => 'bar',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'sum',
            'y_field_id' => $fldNombre, // string, not numeric
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_type');
});

it('accepts a sparkline with a valid y_field_id and numeric aggregation', function () {
    [$manifest, , $fldMonto] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'sparkline',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'sum',
            'y_field_id' => $fldMonto,
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a kanban whose group_by_field_id is not a single_select', function () {
    [$manifest, $fldNombre] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'kanban',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'group_by_field_id' => $fldNombre, // string, not single_select
            'card_title_field_id' => $fldNombre,
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_type');
});

it('rejects a map whose lat_field_id is not numeric', function () {
    [$manifest, $fldNombre, $fldMonto] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'map',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'lat_field_id' => $fldNombre, // string, not number
            'lng_field_id' => $fldMonto, // currency, not number either
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('incompatible_type');
});

it('catches a broken field reference even when nested deep inside tabs and accordion', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'tabs',
            'tabs' => [[
                'id' => id('blk'), 'label' => 'A',
                'blocks' => [[
                    'id' => id('blk'),
                    'type' => 'accordion',
                    'sections' => [[
                        'id' => id('blk'), 'title' => 'S',
                        'blocks' => [[
                            'id' => id('blk'),
                            'type' => 'sparkline',
                            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
                            'aggregation' => 'sum',
                            'y_field_id' => id('fld'),
                        ]],
                    ]],
                ]],
            ]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('catches a run_workflow ref inside a split_view', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'split_view',
            'left_blocks' => [],
            'right_blocks' => [[
                'id' => id('blk'), 'type' => 'button', 'label' => 'Go',
                'on_click' => [['type' => 'run_workflow', 'workflow_id' => id('wkf')]],
            ]],
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('discovers modals nested inside tabs so open_modal still resolves', function () {
    [$manifest] = manifestWithNumericObject();
    $modalId = id('blk');
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [
            [
                'id' => id('blk'),
                'type' => 'tabs',
                'tabs' => [[
                    'id' => id('blk'), 'label' => 'A',
                    'blocks' => [[
                        'id' => $modalId,
                        'type' => 'modal',
                        'title' => 'Edit',
                        'blocks' => [],
                    ]],
                ]],
            ],
            [
                'id' => id('blk'), 'type' => 'button', 'label' => 'Open',
                'on_click' => [['type' => 'open_modal', 'modal_block_id' => $modalId]],
            ],
        ],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts sys_created_at as a sparkline x_field_id', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'sparkline',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'count',
            'x_field_id' => 'sys_created_at',
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts sys_created_at as a heatmap date_field_id', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'heatmap',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'date_field_id' => 'sys_created_at',
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts sys_updated_at inside a table filter gte clause', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => [
                'object_id' => $manifest['objects'][0]['id'],
                'filter' => ['op' => 'gte', 'field_id' => 'sys_updated_at', 'value_expression' => '2024-01-01'],
                'sort' => [['field_id' => 'sys_created_at', 'direction' => 'desc']],
            ],
            'columns' => [['id' => id('col'), 'field_id' => 'sys_created_at']],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('still rejects an unknown sys_ id that is not in the whitelist', function () {
    [$manifest] = manifestWithNumericObject();
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'sparkline',
            'data_source' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'count',
            'x_field_id' => 'sys_nonexistent',
        ]],
    ]];

    $result = (new ManifestValidator)->validate($manifest);
    expect(collect($result->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('accepts rating, slider and date_range field types', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'] = array_merge($manifest['objects'][0]['fields'], [
        ['id' => id('fld'), 'slug' => 'rating', 'name' => 'Rating', 'type' => 'rating', 'max' => 5, 'default' => 3],
        ['id' => id('fld'), 'slug' => 'prob', 'name' => 'Probability', 'type' => 'slider', 'min' => 0, 'max' => 100, 'step' => 5, 'format' => 'percentage'],
        ['id' => id('fld'), 'slug' => 'window', 'name' => 'Window', 'type' => 'date_range'],
    ]);

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects rating with default outside [0, max]', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'r', 'name' => 'R', 'type' => 'rating', 'max' => 5, 'default' => 9,
    ];
    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('incompatible_value');
});

it('rejects slider with min >= max', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 's', 'name' => 'S', 'type' => 'slider', 'min' => 100, 'max' => 10, 'step' => 1,
    ];
    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('incompatible_value');
});

it('rejects slider format=currency without currency_code', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 's', 'name' => 'S', 'type' => 'slider', 'min' => 0, 'max' => 1000, 'step' => 10, 'format' => 'currency',
    ];
    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('missing_required');
});

it('rejects date_range default where from > to', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'w', 'name' => 'W', 'type' => 'date_range',
        'default' => ['from' => '2025-12-31', 'to' => '2025-01-01'],
    ];
    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('incompatible_value');
});

it('accepts a stat block aggregating sum over a rating field', function () {
    [$manifest] = manifestWithNumericObject();
    $ratingId = id('fld');
    $manifest['objects'][0]['fields'][] = [
        'id' => $ratingId, 'slug' => 'rating', 'name' => 'Rating', 'type' => 'rating', 'max' => 5,
    ];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'stat',
            'label' => 'Avg rating',
            'query' => ['object_id' => $manifest['objects'][0]['id']],
            'aggregation' => 'avg',
            'field_id' => $ratingId,
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts a file field type with sensible defaults', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'attachment', 'name' => 'Attachment', 'type' => 'file',
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts a file field with mime_types allowlist and max_size_mb', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'photo', 'name' => 'Photo', 'type' => 'file',
        'max_size_mb' => 5, 'mime_types' => ['image/jpeg', 'image/png', 'image/*'],
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects a file field with malformed mime_types', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'photo', 'name' => 'Photo', 'type' => 'file',
        'mime_types' => ['not-a-mime'],
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('rejects a file field whose max_size_mb is over the hard ceiling', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'huge', 'name' => 'Huge', 'type' => 'file',
        'max_size_mb' => 500,
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('accepts a valid multi_step_form block', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $fldNombre = $manifest['objects'][0]['fields'][0]['id'];
    $fldExtra = id('fld');
    $manifest['objects'][0]['fields'][] = [
        'id' => $fldExtra, 'slug' => 'email', 'name' => 'Email', 'type' => 'string',
    ];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'multi_step_form',
            'object_id' => $objId,
            'mode' => 'create',
            'steps' => [
                [
                    'id' => id('blk'), 'title' => 'Basic',
                    'fields' => [['field_id' => $fldNombre]],
                ],
                [
                    'id' => id('blk'), 'title' => 'Contact',
                    'fields' => [['field_id' => $fldExtra]],
                ],
            ],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects multi_step_form with fewer than 2 steps', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $fldNombre = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'multi_step_form',
            'object_id' => $objId,
            'mode' => 'create',
            'steps' => [
                ['id' => id('blk'), 'title' => 'Only one', 'fields' => [['field_id' => $fldNombre]]],
            ],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeFalse();
});

it('rejects multi_step_form with a field_id that does not belong to the object', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $fldNombre = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'multi_step_form',
            'object_id' => $objId,
            'mode' => 'create',
            'steps' => [
                ['id' => id('blk'), 'title' => 'A', 'fields' => [['field_id' => $fldNombre]]],
                ['id' => id('blk'), 'title' => 'B', 'fields' => [['field_id' => id('fld')]]],
            ],
        ]],
    ]];

    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('unresolved_ref');
});

it('rejects multi_step_form where the same field_id appears in two steps', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $fldNombre = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'multi_step_form',
            'object_id' => $objId,
            'mode' => 'create',
            'steps' => [
                ['id' => id('blk'), 'title' => 'A', 'fields' => [['field_id' => $fldNombre]]],
                ['id' => id('blk'), 'title' => 'B', 'fields' => [['field_id' => $fldNombre]]],
            ],
        ]],
    ]];

    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('duplicate_id');
});

it('rejects multi_step_form with mode=edit but no record_id_expression', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $fldNombre = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'multi_step_form',
            'object_id' => $objId,
            'mode' => 'edit',
            'steps' => [
                ['id' => id('blk'), 'title' => 'A', 'fields' => [['field_id' => $fldNombre]]],
                ['id' => id('blk'), 'title' => 'B', 'fields' => [['field_id' => $fldNombre]]],
            ],
        ]],
    ]];

    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('missing_required');
});

it('accepts a rich_text field with max_length and default', function () {
    $manifest = baseManifest();
    $manifest['objects'][0]['fields'][] = [
        'id' => id('fld'), 'slug' => 'description', 'name' => 'Description', 'type' => 'rich_text',
        'max_length' => 5000, 'default' => '<p>Hello</p>',
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('accepts a table with mixed data and action columns', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $fldId = $manifest['objects'][0]['fields'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => ['object_id' => $objId],
            'columns' => [
                ['id' => id('col'), 'field_id' => $fldId],
                [
                    'id' => id('col'),
                    'type' => 'action',
                    'label' => 'Mark done',
                    'variant' => 'primary',
                    'on_click' => [[
                        'type' => 'update_record',
                        'object_id' => $objId,
                        'record_id_expression' => '{{row.id}}',
                        'values' => ['nombre' => 'done'],
                    ]],
                ],
            ],
        ]],
    ]];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('rejects an action column with a malformed record_id_expression', function () {
    $manifest = baseManifest();
    $objId = $manifest['objects'][0]['id'];
    $manifest['pages'] = [[
        'id' => id('pag'), 'slug' => 'p', 'name' => 'P', 'path' => '/p',
        'blocks' => [[
            'id' => id('blk'),
            'type' => 'table',
            'data_source' => ['object_id' => $objId],
            'columns' => [[
                'id' => id('col'),
                'type' => 'action',
                'label' => 'Edit',
                'on_click' => [[
                    'type' => 'update_record',
                    'object_id' => $objId,
                    'record_id_expression' => '{{row.id', // unbalanced — should be caught
                    'values' => (object) [], // empty object so the JSON Schema accepts it
                ]],
            ]],
        ]],
    ]];

    $r = (new ManifestValidator)->validate($manifest);
    expect(collect($r->errors)->pluck('code'))->toContain('malformed_expression');
});
