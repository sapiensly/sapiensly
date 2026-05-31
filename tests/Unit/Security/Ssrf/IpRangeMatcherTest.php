<?php

use App\Services\Security\Ssrf\IpRangeMatcher;

it('blocks private / reserved / metadata IPv4', function (string $ip) {
    expect((new IpRangeMatcher)->isBlocked($ip))->toBeTrue();
})->with([
    '0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1',
    '169.254.169.254', '172.16.5.5', '192.168.0.1',
    '198.18.0.1', '224.0.0.1', '255.255.255.255',
]);

it('blocks private / reserved IPv6', function (string $ip) {
    expect((new IpRangeMatcher)->isBlocked($ip))->toBeTrue();
})->with([
    '::1', '::', 'fc00::1', 'fe80::1', 'ff02::1', '2001:db8::1',
]);

it('unwraps IPv4-mapped IPv6 before matching', function () {
    $m = new IpRangeMatcher;

    // ::ffff:169.254.169.254 must be blocked via v4 normalization.
    expect($m->isBlocked('::ffff:169.254.169.254'))->toBeTrue()
        ->and($m->isBlocked('::ffff:10.0.0.1'))->toBeTrue()
        // A public address mapped to v6 is still allowed.
        ->and($m->isBlocked('::ffff:93.184.216.34'))->toBeFalse();
});

it('allows public addresses', function (string $ip) {
    expect((new IpRangeMatcher)->isBlocked($ip))->toBeFalse();
})->with([
    '93.184.216.34', '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111',
]);

it('fails closed on an unparseable address', function () {
    expect((new IpRangeMatcher)->isBlocked('not-an-ip'))->toBeTrue();
});
