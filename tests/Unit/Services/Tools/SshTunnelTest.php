<?php

use App\Services\Tools\SshTunnel;

it('builds a hardened ssh -L command', function () {
    $command = (new SshTunnel)->buildCommand(
        ['host' => 'bastion.example.com', 'username' => 'jump', 'port' => 2222],
        15001,
        'db.internal',
        5432,
        '/tmp/key',
    );

    expect($command)
        ->toContain('-N')
        ->toContain('BatchMode=yes')
        ->toContain('ExitOnForwardFailure=yes')
        ->toContain('127.0.0.1:15001:db.internal:5432')
        ->toContain('jump@bastion.example.com')
        ->toContain('/tmp/key');

    // The forward must never disable host-key checking outright.
    expect($command)->not->toContain('StrictHostKeyChecking=no');

    $portFlag = array_search('-p', $command, true);
    expect($command[$portFlag + 1])->toBe('2222');
});

it('drops BatchMode and disables pubkey for password (interactive) auth', function () {
    $command = (new SshTunnel)->buildCommand(
        ['host' => 'b', 'username' => 'u'],
        15003,
        'db',
        5432,
        null,            // no key file → password auth
        true,            // interactive (secret fed via askpass)
    );

    expect($command)
        ->not->toContain('BatchMode=yes')
        ->toContain('PubkeyAuthentication=no')
        ->toContain('PreferredAuthentications=password,keyboard-interactive');
});

it('pins host-key verification to a known_hosts file when given one', function () {
    $command = (new SshTunnel)->buildCommand(
        ['host' => 'b', 'username' => 'u', 'strict_host_key' => 'yes', 'known_hosts_file' => '/etc/known'],
        15002,
        'db',
        5432,
        null,
    );

    expect($command)
        ->toContain('StrictHostKeyChecking=yes')
        ->toContain('UserKnownHostsFile=/etc/known');
});
