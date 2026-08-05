<?php

use App\Services\Manifest\ManifestPatch;
use App\Services\Manifest\ManifestPatchException;

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

// --- the `append` extension: long strings written/revised in chunks ---

it('append concatenates onto an existing string value', function () {
    $doc = ['settings' => ['custom_css' => '.a{color:red}']];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => "\n.b{color:blue}"],
    ]);

    expect($result['settings']['custom_css'])->toBe(".a{color:red}\n.b{color:blue}");
});

it('append starts from empty on an absent or null leaf', function () {
    $result = ManifestPatch::apply(['settings' => []], [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => '.a{}'],
    ]);
    expect($result['settings']['custom_css'])->toBe('.a{}');

    $result = ManifestPatch::apply(['settings' => ['custom_css' => null]], [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => '.a{}'],
    ]);
    expect($result['settings']['custom_css'])->toBe('.a{}');
});

it('consecutive appends in one call stack in order', function () {
    $result = ManifestPatch::apply(['settings' => []], [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => '.a{}'],
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => '.b{}'],
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => '.c{}'],
    ]);

    expect($result['settings']['custom_css'])->toBe('.a{}.b{}.c{}');
});

it('append rejects a non-string target, a non-string value, and a missing parent', function () {
    expect(fn () => ManifestPatch::apply(['objects' => []], [
        ['op' => 'append', 'path' => '/objects', 'value' => 'x'],
    ]))->toThrow(InvalidArgumentException::class, 'only works on string');

    expect(fn () => ManifestPatch::apply(['settings' => []], [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => 42],
    ]))->toThrow(InvalidArgumentException::class, 'string `value`');

    expect(fn () => ManifestPatch::apply(['objects' => []], [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => 'x'],
    ]))->toThrow(InvalidArgumentException::class, 'does not exist');
});

/**
 * This used to assert the opposite — that a block's html could be streamed the
 * way custom_css is. In isolation the concatenation is fine, which is exactly
 * why the bug hid here: the damage happens between two saves, when the
 * sanitiser re-parses the partial markup and closes the tags the author left
 * open. The next chunk then lands outside the element it belonged to.
 */
it('refuses to append to a block content — markup cannot be streamed', function () {
    $doc = ['pages' => [['blocks' => [['id' => 'blk_1', 'type' => 'html', 'content' => '<section>']]]]];

    expect(fn () => ManifestPatch::apply($doc, [
        ['op' => 'append', 'path' => '/pages/0/blocks/0/content', 'value' => '<h1>Hola</h1></section>'],
    ]))->toThrow(InvalidArgumentException::class, 'one `add` op');
});

/**
 * A block's html is re-parsed and REPAIRED on every save, so a partial chunk
 * comes back with its open tags closed and the next append lands outside the
 * element it belonged to. It fails silently — every patch reports success and
 * the manifest reads fine — so it is refused at the patch layer instead.
 *
 * Cost a live rebuild half its logo marquee, rendering as a stray row below
 * its own track, after six "successful" patches.
 */
it('refuses it for a block nested inside another block too', function () {
    $doc = ['pages' => [['blocks' => [['id' => 'blk_a', 'type' => 'html', 'content' => '<section><div>']]]]];

    expect(fn () => ManifestPatch::apply($doc, [
        ['op' => 'append', 'path' => '/pages/0/blocks/2/blocks/1/content', 'value' => 'x'],
    ]))->toThrow(InvalidArgumentException::class, 'append cannot write');
});

it('still allows a block content to be written whole', function () {
    $doc = ['pages' => [['blocks' => [['id' => 'blk_a', 'type' => 'html', 'content' => 'old']]]]];

    $result = ManifestPatch::apply($doc, [
        ['op' => 'add', 'path' => '/pages/0/blocks/0/content', 'value' => '<section><p>whole</p></section>'],
    ]);

    expect($result['pages'][0]['blocks'][0]['content'])->toBe('<section><p>whole</p></section>');
});

it('keeps streaming custom_css, which is not markup', function () {
    $result = ManifestPatch::apply(['settings' => ['custom_css' => '.a{}']], [
        ['op' => 'append', 'path' => '/settings/custom_css', 'value' => '.b{}'],
    ]);

    expect($result['settings']['custom_css'])->toBe('.a{}.b{}');
});

/**
 * A rejected patch used to come back as "An Operation failed" and nothing else.
 * Applied to a batch that is no information at all — not which op, not which
 * segment, not whether an index was out of range or a `test` disagreed.
 * Observed live: a builder turn burned ~20 rejected calls hunting a column
 * index inside a tabs block, because every rejection read identically. The
 * engine was right the whole time; the address was off by a few.
 */
it('names the op that failed and where the path stops resolving', function () {
    $doc = ['pages' => [[
        'id' => 'pag_x', 'slug' => 'ordenes', 'blocks' => [[
            'id' => 'blk_tabs', 'type' => 'tabs', 'tabs' => [[
                'id' => 'tab_lista', 'blocks' => [[
                    'id' => 'blk_tabla', 'type' => 'table',
                    'columns' => [['id' => 'col_a'], ['id' => 'col_b']],
                ]],
            ]],
        ]],
    ]]];

    expect(fn () => ManifestPatch::apply($doc, [
        ['op' => 'replace', 'path' => '/pages/0/blocks/0/tabs/0/blocks/0/columns/23/id', 'value' => 'x'],
    ]))->toThrow(
        ManifestPatchException::class,
        'holds 2 item(s), so "23" is out of range',
    );
});

it('points at the failing op inside a batch, not at the batch', function () {
    $doc = ['pages' => [['id' => 'pag_x', 'slug' => 'ordenes']]];

    try {
        ManifestPatch::apply($doc, [
            ['op' => 'replace', 'path' => '/pages/0/slug', 'value' => 'ok'],
            ['op' => 'replace', 'path' => '/pages/9/slug', 'value' => 'x'],
        ]);
        expect(false)->toBeTrue('the second op should have failed');
    } catch (ManifestPatchException $e) {
        expect($e->opIndex)->toBe(1)
            ->and($e->pointer())->toBe('/ops/1')
            ->and($e->opPath)->toBe('/pages/9/slug');
    }
});

it('lists the keys that are there when a property name is wrong', function () {
    $doc = ['pages' => [['id' => 'pag_x', 'slug' => 'ordenes', 'blocks' => []]]];

    expect(fn () => ManifestPatch::apply($doc, [
        ['op' => 'replace', 'path' => '/pages/0/blocs/0', 'value' => 'x'],
    ]))->toThrow(ManifestPatchException::class, 'has no "blocs"');
});

it('surfaces the real cause of a failed test op', function () {
    // The library hides this one level down, in the exception's `previous`.
    $doc = ['version' => 1];

    expect(fn () => ManifestPatch::apply($doc, [
        ['op' => 'test', 'path' => '/version', 'value' => 99],
    ]))->toThrow(ManifestPatchException::class, 'does not match expected value');
});

it('still applies a correct deep path inside a tabs block', function () {
    // The engine was never the problem — pin that, so the error work above is
    // not mistaken for a fix to addressing.
    $doc = ['pages' => [['blocks' => [['type' => 'tabs', 'tabs' => [[
        'blocks' => [['type' => 'table', 'columns' => [
            ['id' => 'col_a'],
            ['id' => 'col_open', 'on_click' => [['type' => 'navigate', 'to' => '/ordenes_detail']]],
        ]]],
    ]]]]]]];

    $result = ManifestPatch::apply($doc, [[
        'op' => 'replace',
        'path' => '/pages/0/blocks/0/tabs/0/blocks/0/columns/1/on_click/0/to',
        'value' => '/ordenes_detail_2',
    ]]);

    expect($result['pages'][0]['blocks'][0]['tabs'][0]['blocks'][0]['columns'][1]['on_click'][0]['to'])
        ->toBe('/ordenes_detail_2');
});
