<?php

use App\Services\Records\HtmlSanitizer;

beforeEach(function () {
    $this->sanitizer = new HtmlSanitizer;
});

it('keeps allowed inline marks', function () {
    $html = '<p>Hello <strong>world</strong> and <em>peace</em></p>';
    expect($this->sanitizer->sanitize($html))->toContain('<strong>world</strong>')
        ->and($this->sanitizer->sanitize($html))->toContain('<em>peace</em>');
});

it('keeps headings, lists and links', function () {
    $html = '<h2>Title</h2><ul><li>One</li><li>Two</li></ul><p><a href="https://example.com">click</a></p>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->toContain('<h2>Title</h2>')
        ->and($out)->toContain('<li>One</li>')
        ->and($out)->toContain('<a href="https://example.com"');
});

it('strips <script> tags entirely (and keeps surrounding text)', function () {
    $html = '<p>Safe<script>alert(1)</script>text</p>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('<script')
        ->and($out)->not->toContain('alert(1)') // text content of script is dropped
        ->and($out)->toContain('Safe')
        ->and($out)->toContain('text');
});

it('strips inline event handlers from allowed tags', function () {
    $html = '<p onclick="evil()">click me</p>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('onclick')
        ->and($out)->toContain('<p>click me</p>');
});

it('removes javascript: URLs from <a href>', function () {
    $html = '<a href="javascript:alert(1)">click</a>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('javascript:')
        ->and($out)->toContain('click');
});

it('removes data: URLs from <a href>', function () {
    $html = '<a href="data:text/html,&lt;script&gt;alert(1)&lt;/script&gt;">x</a>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('data:');
});

it('preserves mailto and tel schemes', function () {
    $html = '<a href="mailto:a@b.com">mail</a> <a href="tel:+1-555">phone</a>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->toContain('mailto:a@b.com')
        ->and($out)->toContain('tel:+1-555');
});

it('forces target=_blank + rel on external http(s) links', function () {
    $html = '<a href="https://example.com">x</a>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->toContain('target="_blank"')
        ->and($out)->toContain('rel="noopener noreferrer"');
});

it('drops dangerous tags entirely (along with their text) but preserves siblings', function () {
    $html = '<p>Before<iframe src="http://evil">danger</iframe>After</p>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('<iframe')
        ->and($out)->not->toContain('danger')
        ->and($out)->toContain('Before')
        ->and($out)->toContain('After');
});

it('unwraps benign disallowed tags and keeps their text', function () {
    // <div> is not dangerous, just not on the allowlist — keep the text.
    $html = '<p>Hello <div>nested</div> world</p>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('<div')
        ->and($out)->toContain('nested');
});

it('strips HTML comments entirely', function () {
    $html = '<p>Visible<!-- secret --></p>';
    $out = $this->sanitizer->sanitize($html);
    expect($out)->not->toContain('<!--')
        ->and($out)->not->toContain('secret');
});

it('returns empty for empty input', function () {
    expect($this->sanitizer->sanitize(''))->toBe('')
        ->and($this->sanitizer->sanitize('   '))->toBe('');
});

it('plainText strips tags for length checks', function () {
    expect($this->sanitizer->plainText('<p>Hello <strong>world</strong></p>'))
        ->toBe('Hello world');
});
