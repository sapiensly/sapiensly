<?php

use App\Services\Chat\ChatAiService;

it('leaves a balanced artifact untouched', function () {
    $content = 'Here you go: <artifact title="x" type="html">…</artifact> done.';

    expect(ChatAiService::closeDanglingArtifacts($content))->toBe($content);
});

it('appends the missing close tag for a dangling artifact', function () {
    $content = '¡Listo! <artifact title="grafica" type="html"><html>…';

    expect(ChatAiService::closeDanglingArtifacts($content))
        ->toBe($content.'</artifact>');
});

it('balances multiple dangling artifacts', function () {
    $content = '<artifact type="code">a</artifact><artifact type="html">b';

    expect(ChatAiService::closeDanglingArtifacts($content))
        ->toBe($content.'</artifact>');
});

it('ignores a reply truncated mid opening tag', function () {
    $content = 'Generating <artifact title="x" type="htm';

    expect(ChatAiService::closeDanglingArtifacts($content))->toBe($content);
});

it('returns content without artifacts unchanged', function () {
    $content = 'Just a plain reply with no artifacts.';

    expect(ChatAiService::closeDanglingArtifacts($content))->toBe($content);
});
