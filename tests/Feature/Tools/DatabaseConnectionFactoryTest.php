<?php

use App\Services\Tools\DatabaseConnectionFactory;
use App\Services\Tools\SshTunnel;
use App\Services\Tools\SshTunnelHandle;

it('routes the connection through an SSH tunnel when configured', function () {
    $tunnel = Mockery::mock(SshTunnel::class);
    $tunnel->shouldReceive('open')
        ->once()
        ->with(
            Mockery::on(fn (array $ssh): bool => $ssh['host'] === 'bastion.example.com'),
            'db.internal',
            5432,
        )
        ->andReturn(new SshTunnelHandle(54321));

    $factory = new DatabaseConnectionFactory($tunnel);

    $name = $factory->open([
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => 5432,
        'database' => 'analytics',
        'username' => 'u',
        'password' => 'p',
        'ssh' => ['host' => 'bastion.example.com', 'username' => 'jump', 'private_key' => 'KEY'],
    ]);

    // The PDO connection points at the tunnel's local end, not the real host.
    $config = config("database.connections.{$name}");
    expect($config['host'])->toBe('127.0.0.1');
    expect($config['port'])->toBe(54321);

    $factory->close($name);
    expect(config("database.connections.{$name}"))->toBeNull();
});

it('opens a direct connection when there is no SSH block', function () {
    $tunnel = Mockery::mock(SshTunnel::class);
    $tunnel->shouldNotReceive('open');

    $factory = new DatabaseConnectionFactory($tunnel);

    $name = $factory->open([
        'driver' => 'pgsql',
        'host' => 'db.example.com',
        'port' => 5432,
        'database' => 'x',
        'username' => 'u',
        'password' => 'p',
    ]);

    expect(config("database.connections.{$name}")['host'])->toBe('db.example.com');

    $factory->close($name);
});
