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
