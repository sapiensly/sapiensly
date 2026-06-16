<?php

use App\Services\Manifest\ManifestPatch;

it('appends to workflows even when the key is absent (no silent drop)', function () {
    $doc = ['objects' => [], 'pages' => []]; // brand-new app: no workflows key

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/workflows/-', 'value' => ['id' => 'wkf_1', 'slug' => 'w']],
    ]);

    expect($result['workflows'])->toBe([['id' => 'wkf_1', 'slug' => 'w']]);
});

it('appends to an existing workflows array', function () {
    $doc = ['workflows' => [['id' => 'wkf_1']]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/workflows/-', 'value' => ['id' => 'wkf_2']],
    ]);

    expect($result['workflows'])->toBe([['id' => 'wkf_1'], ['id' => 'wkf_2']]);
});

it('keeps workflows a list (not an object) after appending', function () {
    $result = ManifestPatch::apply(['objects' => []], [
        ['op' => 'add', 'path' => '/workflows/-', 'value' => ['id' => 'wkf_1']],
    ]);

    expect(array_is_list($result['workflows']))->toBeTrue();
});

it('applies a normal replace untouched', function () {
    $result = ManifestPatch::apply(['name' => 'Old', 'workflows' => []], [
        ['op' => 'replace', 'path' => '/name', 'value' => 'New'],
    ]);

    expect($result['name'])->toBe('New');
});

it('inserts an object block at a numeric index without spreading it into scalars', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_table', 'type' => 'table'],
        ['id' => 'blk_heading', 'type' => 'heading'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/pages/0/blocks/1', 'value' => ['id' => 'blk_button', 'type' => 'button']],
    ]);

    expect($result['pages'][0]['blocks'])->toBe([
        ['id' => 'blk_table', 'type' => 'table'],
        ['id' => 'blk_button', 'type' => 'button'],
        ['id' => 'blk_heading', 'type' => 'heading'],
    ]);
});

it('applies two sequential index inserts in one patch', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_table', 'type' => 'table'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/pages/0/blocks/0', 'value' => ['id' => 'blk_modal', 'type' => 'modal']],
        ['op' => 'add', 'path' => '/pages/0/blocks/1', 'value' => ['id' => 'blk_button', 'type' => 'button']],
    ]);

    expect(array_column($result['pages'][0]['blocks'], 'id'))
        ->toBe(['blk_modal', 'blk_button', 'blk_table']);
});

it('inserting at index equal to the count appends', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_table', 'type' => 'table'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/pages/0/blocks/1', 'value' => ['id' => 'blk_button', 'type' => 'button']],
    ]);

    expect(array_column($result['pages'][0]['blocks'], 'id'))->toBe(['blk_table', 'blk_button']);
});

it('reorders a block via move within the same array', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_a', 'type' => 'table'],
        ['id' => 'blk_b', 'type' => 'button'],
        ['id' => 'blk_c', 'type' => 'heading'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'move', 'from' => '/pages/0/blocks/2', 'path' => '/pages/0/blocks/0'],
    ]);

    expect(array_column($result['pages'][0]['blocks'], 'id'))->toBe(['blk_c', 'blk_a', 'blk_b']);
});

it('copies a block to a numeric index as a single element', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_a', 'type' => 'table'],
        ['id' => 'blk_b', 'type' => 'button'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'copy', 'from' => '/pages/0/blocks/1', 'path' => '/pages/0/blocks/0'],
    ]);

    expect($result['pages'][0]['blocks'])->toBe([
        ['id' => 'blk_b', 'type' => 'button'],
        ['id' => 'blk_a', 'type' => 'table'],
        ['id' => 'blk_b', 'type' => 'button'],
    ]);
});

it('still appends via the /- token (library path) untouched', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_a', 'type' => 'table'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/pages/0/blocks/-', 'value' => ['id' => 'blk_b', 'type' => 'button']],
    ]);

    expect(array_column($result['pages'][0]['blocks'], 'id'))->toBe(['blk_a', 'blk_b']);
});

it('replacing at a numeric index overwrites rather than inserts', function () {
    $doc = ['pages' => [['id' => 'pg_1', 'blocks' => [
        ['id' => 'blk_a', 'type' => 'table'],
        ['id' => 'blk_b', 'type' => 'button'],
    ]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'replace', 'path' => '/pages/0/blocks/0', 'value' => ['id' => 'blk_c', 'type' => 'heading']],
    ]);

    expect(array_column($result['pages'][0]['blocks'], 'id'))->toBe(['blk_c', 'blk_b']);
});
