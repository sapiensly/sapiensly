<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;

/**
 * Blocks until the given Redis queues have no reserved (in-flight) jobs — the
 * deploy-safety half of a graceful restart. `horizon:pause` stops workers from
 * popping NEW jobs; this waits for what is already running to finish before
 * the deploy swaps code and vendor under the workers' feet. A worker
 * hard-killed mid-job leaves the job invisibly reserved in Redis until
 * `retry_after` (900s) expires — a silent 15 minutes before the user learns
 * their build died.
 *
 * The default queue list is every queue whose jobs run long enough (300s
 * class) to be worth waiting for; short-job queues drain within any sane stop
 * window on their own. Exits 0 once idle, 1 on timeout — deploy scripts should
 * treat 1 as "proceed, but an in-flight job may be interrupted".
 */
#[Signature('queue:drain
    {--queues=ai,workflows,debate,agent-responses : Comma-separated queues to wait on}
    {--timeout=330 : Maximum seconds to wait before giving up}
    {--poll=3 : Seconds between checks}')]
#[Description('Wait until the given Redis queues have no in-flight jobs (pair with horizon:pause for graceful deploys).')]
class WaitForQueuesToDrain extends Command
{
    public function handle(): int
    {
        $queues = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('queues')))));
        $timeout = max(1, (int) $this->option('timeout'));
        $poll = max(1, (int) $this->option('poll'));

        $connection = Queue::connection('redis');

        if (! $connection instanceof RedisQueue) {
            $this->error('The [redis] queue connection is not a Redis queue; nothing to drain.');

            return self::FAILURE;
        }

        $deadline = microtime(true) + $timeout;

        while (true) {
            $reserved = collect($queues)
                ->mapWithKeys(fn (string $queue) => [$queue => (int) $connection->reservedSize($queue)])
                ->filter();

            if ($reserved->sum() === 0) {
                $this->info('Queues are idle: '.implode(', ', $queues).'.');

                return self::SUCCESS;
            }

            $summary = $reserved->map(fn (int $count, string $queue) => "{$queue}: {$count}")->implode(', ');

            if (microtime(true) >= $deadline) {
                $this->warn("Timed out after {$timeout}s with job(s) still in flight ({$summary}).");

                return self::FAILURE;
            }

            $this->line("Waiting on in-flight job(s): {$summary}");

            sleep($poll);
        }
    }
}
