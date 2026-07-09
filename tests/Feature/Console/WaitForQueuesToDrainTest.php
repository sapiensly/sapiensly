<?php

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Uses a throwaway queue name so the assertions never touch the real ai/
 * workflows queues on the shared local Redis.
 */
function drainTestRedis(): array
{
    /** @var RedisQueue $connection */
    $connection = Queue::connection('redis');

    return [$connection->getConnection(), 'queues:drain-test:reserved'];
}

afterEach(function () {
    [$redis, $key] = drainTestRedis();
    $redis->del($key);
});

it('exits successfully once the queues have no reserved jobs', function () {
    $this->artisan('queue:drain', ['--queues' => 'drain-test', '--timeout' => 2, '--poll' => 1])
        ->expectsOutputToContain('Queues are idle')
        ->assertSuccessful();
});

it('fails after the timeout while a job is still reserved', function () {
    [$redis, $key] = drainTestRedis();
    $redis->zadd($key, time() + 300, 'fake-reserved-job');

    $this->artisan('queue:drain', ['--queues' => 'drain-test', '--timeout' => 1, '--poll' => 1])
        ->expectsOutputToContain('Timed out')
        ->assertFailed();
});
