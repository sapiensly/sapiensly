<?php

use App\Support\Storage\TenantPath;

test('prefix uses the org folder in business mode (org wins over user)', function () {
    expect(TenantPath::prefix('org_123', 5))->toBe('org/org_123');
});

test('prefix uses the user folder in personal mode (no org)', function () {
    expect(TenantPath::prefix(null, 5))->toBe('user/5');
});

test('scope prepends the tenant prefix to a content-relative path', function () {
    expect(TenantPath::scope('org_123', 5, 'documents/doc_1/file.pdf'))
        ->toBe('org/org_123/documents/doc_1/file.pdf')
        ->and(TenantPath::scope(null, 7, '/chat_uploads/c1/a1.png'))
        ->toBe('user/7/chat_uploads/c1/a1.png');
});

test('it fails closed without an organization or user (no bucket-root writes)', function () {
    expect(fn () => TenantPath::prefix(null, null))->toThrow(InvalidArgumentException::class)
        ->and(fn () => TenantPath::prefix('', null))->toThrow(InvalidArgumentException::class);
});
