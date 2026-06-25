<?php

use App\Services\Manifest\ManifestSchemaCatalog;
use App\Services\Manifest\ManifestValidator;

function catalog(): ManifestSchemaCatalog
{
    return new ManifestSchemaCatalog(new ManifestValidator);
}

it('resolves a field type to its required/optional params and enum values', function () {
    $params = catalog()->params('field', 'relation');

    expect($params['required'])->toContain('type', 'target_object_id', 'cardinality')
        ->and($params['optional'])->toContain('on_delete', 'inverse_field_id')
        ->and($params['values']['type'])->toBe(['relation'])
        ->and($params['values']['cardinality'])->toBe(['one_to_one', 'many_to_one', 'one_to_many', 'many_to_many']);
});

it('builds a fill-in example skeleton with the concrete type and a real enum value', function () {
    $example = catalog()->example('field', 'relation');

    expect($example['type'])->toBe('relation')
        ->and($example['cardinality'])->toBe('one_to_one')
        ->and($example)->toHaveKeys(['id', 'slug', 'name', 'target_object_id']);
});

it('resolves an action oneOf branch by its const discriminator', function () {
    $params = catalog()->params('action', 'create_record');

    expect($params['required'])->toContain('type', 'object_id', 'values')
        ->and($params['values']['type'])->toBe(['create_record']);
});

it('resolves a step branch whose const lives under allOf', function () {
    $params = catalog()->params('step', 'record.query');

    expect($params['required'])->toContain('type', 'object_id')
        ->and($params['values']['type'])->toBe(['record.query']);
});

it('matches a trigger against an enum discriminator branch', function () {
    $params = catalog()->params('trigger', 'record.updated');

    expect($params['required'])->toContain('type', 'object_id')
        ->and($params['values']['type'])->toContain('record.created', 'record.updated', 'record.deleted');

    // The example pins the specific requested event, not the first enum member.
    expect(catalog()->example('trigger', 'record.updated')['type'])->toBe('record.updated');
});

it('maps a component to its block definition name', function () {
    expect(catalog()->definitionName('component', 'table'))->toBe('block_table')
        ->and(catalog()->definitionName('field', 'relation'))->toBe('field_relation');
});

it('returns null for an unknown type instead of throwing', function () {
    expect(catalog()->params('field', 'nonexistent'))->toBeNull()
        ->and(catalog()->example('component', 'nope'))->toBeNull()
        ->and(catalog()->subSchema('action', 'nope'))->toBeNull();
});
