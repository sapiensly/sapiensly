<?php

use App\Services\Tools\SshTunnel;

it('fails with a clear, actionable error when the ssh client is missing', function () {
    config(['integrations.ssh_binary' => '/nonexistent/ssh']);

    expect(fn () => (new SshTunnel)->open(
        ['host' => 'bastion', 'username' => 'jump', 'private_key' => 'KEY'],
        'db.internal',
        5432,
    ))->toThrow(RuntimeException::class, 'openssh-client');
});
