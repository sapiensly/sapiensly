<?php

use App\Support\Css\ScopedAppCss;

it('passes clean CSS with no issues', function () {
    expect(ScopedAppCss::issues('.card { color: var(--sp-accent); }'))->toBe([]);
    expect(ScopedAppCss::issues(null))->toBe([]);
    expect(ScopedAppCss::issues(''))->toBe([]);
});

it('flags the forbidden constructs', function () {
    expect(ScopedAppCss::issues('@import url(http://evil.test/x.css);'))->not->toBe([]);
    expect(ScopedAppCss::issues('a { x: expression(alert(1)) }'))->not->toBe([]);
    expect(ScopedAppCss::issues('a { background: url(javascript:alert(1)) }'))->not->toBe([]);
    expect(ScopedAppCss::issues('</style><script>alert(1)</script>'))->not->toBe([]);
    expect(ScopedAppCss::issues(str_repeat('a', ScopedAppCss::MAX_LENGTH + 1)))->not->toBe([]);
});

it('scopes author CSS to the app surface via nesting', function () {
    $out = ScopedAppCss::compile('.card { color: red; }');

    expect($out)->toStartWith('.sp-app-surface {')
        ->and($out)->toContain('.card { color: red; }')
        ->and($out)->toEndWith('}');
});

it('returns an empty string for blank input', function () {
    expect(ScopedAppCss::compile(null))->toBe('')
        ->and(ScopedAppCss::compile('   '))->toBe('');
});

it('strips a style/script breakout as a backstop', function () {
    $out = ScopedAppCss::compile('.x{}</style><script>evil()</script>');

    expect($out)->not->toContain('<script')
        ->and($out)->not->toContain('</style');
});

it('accepts a custom scope', function () {
    expect(ScopedAppCss::compile('.x{}', '#preview'))->toStartWith('#preview {');
});
