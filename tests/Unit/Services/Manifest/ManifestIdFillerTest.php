<?php

use App\Services\Manifest\ManifestIdFiller;

function filledValue(array $op): array
{
    return ManifestIdFiller::fill([$op])[0]['value'];
}

it('mints object, field and option ids when omitted', function () {
    $value = filledValue([
        'op' => 'add', 'path' => '/objects/-',
        'value' => [
            'slug' => 'platillos', 'name' => 'Platillos',
            'fields' => [
                ['slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
                ['slug' => 'estado', 'name' => 'Estado', 'type' => 'single_select', 'options' => [
                    ['value' => 'abierta', 'label' => 'Abierta'],
                ]],
            ],
        ],
    ]);

    expect($value['id'])->toStartWith('obj_');
    expect($value['fields'][0]['id'])->toStartWith('fld_');
    expect($value['fields'][1]['id'])->toStartWith('fld_');
    expect($value['fields'][1]['options'][0]['id'])->toStartWith('opt_');
});

it('mints a block id and table column ids', function () {
    $value = filledValue([
        'op' => 'add', 'path' => '/pages/0/blocks/-',
        'value' => [
            'type' => 'table',
            'data_source' => ['object_id' => 'obj_x'],
            'columns' => [['field_id' => 'fld_x'], ['field_id' => 'fld_y']],
        ],
    ]);

    expect($value['id'])->toStartWith('blk_');
    expect($value['columns'][0]['id'])->toStartWith('col_');
    expect($value['columns'][1]['id'])->toStartWith('col_');
    // data_source is additionalProperties:false — must NOT get an id.
    expect($value['data_source'])->not->toHaveKey('id');
});

it('does NOT add ids to form field references', function () {
    $value = filledValue([
        'op' => 'add', 'path' => '/pages/0/blocks/-',
        'value' => [
            'type' => 'form', 'object_id' => 'obj_x', 'mode' => 'create',
            'fields' => [['field_id' => 'fld_x']],
        ],
    ]);

    expect($value['id'])->toStartWith('blk_');
    expect($value['fields'][0])->toBe(['field_id' => 'fld_x']); // untouched, no id
});

it('does NOT add ids to related_list columns', function () {
    $value = filledValue([
        'op' => 'add', 'path' => '/pages/0/blocks/-',
        'value' => [
            'type' => 'related_list', 'object_id' => 'obj_x',
            'via_relation_field_id' => 'fld_r', 'parent_id_expression' => '{{params.id}}',
            'columns' => [['field_id' => 'fld_x']],
        ],
    ]);

    expect($value['columns'][0])->toBe(['field_id' => 'fld_x']); // no id
});

it('mints metric_grid item ids and recurses split_view block trees', function () {
    $grid = filledValue([
        'op' => 'add', 'path' => '/pages/0/blocks/-',
        'value' => ['type' => 'metric_grid', 'items' => [
            ['label' => 'Total', 'query' => ['object_id' => 'obj_x'], 'aggregation' => 'count'],
        ]],
    ]);
    expect($grid['items'][0]['id'])->toStartWith('itm_');
    expect($grid['items'][0]['query'])->not->toHaveKey('id');

    $split = filledValue([
        'op' => 'add', 'path' => '/pages/0/blocks/-',
        'value' => [
            'type' => 'split_view',
            'left_blocks' => [['type' => 'heading', 'content' => 'A']],
            'right_blocks' => [['type' => 'table', 'data_source' => ['object_id' => 'o'], 'columns' => [['field_id' => 'f']]]],
        ],
    ]);
    expect($split['id'])->toStartWith('blk_');
    expect($split['left_blocks'][0]['id'])->toStartWith('blk_');
    expect($split['right_blocks'][0]['id'])->toStartWith('blk_');
    expect($split['right_blocks'][0]['columns'][0]['id'])->toStartWith('col_');
});

it('leaves existing ids and non-id values untouched', function () {
    $value = filledValue([
        'op' => 'add', 'path' => '/pages/0/blocks/-',
        'value' => ['id' => 'blk_keep0001', 'type' => 'heading', 'content' => 'Hi'],
    ]);
    expect($value['id'])->toBe('blk_keep0001');

    // A scalar replace (e.g. the app name) is returned unchanged.
    $ops = ManifestIdFiller::fill([['op' => 'replace', 'path' => '/name', 'value' => 'New Name']]);
    expect($ops[0]['value'])->toBe('New Name');
});
