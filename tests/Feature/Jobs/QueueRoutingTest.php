<?php

use App\Jobs\RunBuilderAiJob;
use Illuminate\Queue\Attributes\Queue as QueueAttribute;
use Illuminate\Support\Facades\Queue;

/**
 * Every AI job must land on the supervisor that can actually finish it.
 *
 * `default` kills a worker at timeout=60. A builder turn takes 80-115s, a chat
 * turn streams for minutes, a debate round longer still. So the queue name is not
 * a tuning detail — it is whether the work completes at all.
 *
 * For a long time it did not. Fifteen jobs declared `viaQueue()`, which LOOKS like
 * the framework hook and is not one: Illuminate\Bus\Dispatcher never calls it (only
 * the event dispatcher and notifications do), so every one of them silently ran on
 * `default` and any turn that went quiet for a minute was killed with
 * MaxAttemptsExceeded. The docblocks explained, correctly and at length, why that
 * must never happen — above a method the framework does not call.
 *
 * These tests assert the routing through the real dispatcher, so a mechanism that
 * does not work cannot pass by looking right.
 */
it('dispatches the builder onto the ai queue, through the real dispatcher', function () {
    Queue::fake();

    RunBuilderAiJob::dispatch('bmsg_test', 'hola');

    // Not "the class declares a queue" — where the dispatcher ACTUALLY put it.
    Queue::assertPushedOn('ai', RunBuilderAiJob::class);
});

it('routes every AI job to a supervisor that can outlive its turn', function () {
    // The queue each job needs, and why it cannot be `default` (timeout=60):
    //   ai (300s)              — builder/chat/agent/express/slides turns
    //   debate (300s)          — a debate turn streams a full argument
    //   agent-responses (300s) — each agent turn streams a full reply
    $expected = [
        'AssessDebateRoundJob' => 'debate',
        'ExpressDashboardJob' => 'ai',
        'RefreshDeckJob' => 'ai',
        'ResolveStoppedBuildJob' => 'ai',
        'RunBuilderAiJob' => 'ai',
        'RunChatAiJob' => 'ai',
        'RunDebateTurnJob' => 'debate',
        'RunRuntimeAgentJob' => 'ai',
        'RunSlideBuilderJob' => 'ai',
        'StartDebateJob' => 'debate',
        'SummarizeChatHistoryJob' => 'ai',
        'SynthesizeDebateJob' => 'debate',
        'VerifyExpressDashboardJob' => 'ai',
        'Chat\\InvokeAgentResponse' => 'agent-responses',
        'Chat\\SynthesizeThread' => 'agent-responses',
    ];

    foreach ($expected as $class => $queue) {
        $fqcn = 'App\\Jobs\\'.$class;
        $attributes = (new ReflectionClass($fqcn))->getAttributes(QueueAttribute::class);

        expect($attributes)->toHaveCount(1, "{$class} declares no #[Queue] — it will run on `default` (timeout=60) and be killed mid-turn.");
        expect($attributes[0]->newInstance()->queue)->toBe($queue, "{$class} is routed to the wrong supervisor.");
    }
});

it('lets no job go back to declaring a queue with a method the dispatcher never calls', function () {
    // The specific trap: `viaQueue()` is a real Laravel hook — for event listeners
    // and notifications. On a Job it is dead code that reads like configuration,
    // which is why it survived in fifteen files with docblocks defending it.
    $offenders = [];
    foreach (glob(app_path('Jobs').'/{*,*/*}.php', GLOB_BRACE) as $file) {
        if (str_contains((string) file_get_contents($file), 'function viaQueue')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});
