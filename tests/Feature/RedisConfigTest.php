<?php

use Illuminate\Redis\Connectors\PhpRedisConnector;

test('redis connections expose a scheme so TLS can be enabled for managed providers', function () {
    foreach (['default', 'cache'] as $connection) {
        expect(config("database.redis.{$connection}"))
            ->toHaveKey('scheme')
            ->and(config("database.redis.{$connection}.scheme"))
            ->toBe('tcp');
    }
});

test('a tls scheme makes the connector dial the host over tls', function () {
    $connector = new PhpRedisConnector;

    $format = (new ReflectionClass($connector))->getMethod('formatHost');
    $format->setAccessible(true);

    expect($format->invoke($connector, ['scheme' => 'tls', 'host' => 'db.upstash.io']))
        ->toBe('tls://db.upstash.io')
        ->and($format->invoke($connector, ['scheme' => 'tcp', 'host' => '127.0.0.1']))
        ->toBe('tcp://127.0.0.1');
});
