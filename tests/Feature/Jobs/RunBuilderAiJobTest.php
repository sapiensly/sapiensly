<?php

use App\Jobs\RunBuilderAiJob;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Str;

function usage_snapshot(array $overrides = []): array
{
    return array_merge([
        'model' => 'anthropic/claude-opus-5',
        'prompt_tokens' => 1200,
        'completion_tokens' => 340,
        'cache_read_input_tokens' => 800,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'recorded' => false,
    ], $overrides);
}

function job_manifest(string $appId): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $appId,
        'slug' => 'mini_crm',
        'name' => 'Mini CRM',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_'.strtolower((string) Str::ulid()),
            'slug' => 'clientes',
            'name' => 'Cliente',
            'fields' => [
                ['id' => 'fld_'.strtolower((string) Str::ulid()), 'slug' => 'nombre', 'name' => 'Nombre', 'type' => 'string'],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create();
    app(AppManifestService::class)->createVersion($this->testApp, job_manifest($this->testApp->id), $this->user);
    $this->conversation = BuilderConversation::create([
        'organization_id' => $this->testApp->organization_id,
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
});

it('failed() banks a checkpointed patch instead of discarding the timed-out turn', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
        'proposed_patch' => [['op' => 'replace', 'path' => '/name', 'value' => 'Outbound Calls']],
        'change_summary' => 'created the app',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('applied')
        ->and($fresh->applied_version_id)->not->toBeNull()
        ->and(app(AppManifestService::class)->getActiveManifest($this->testApp->fresh())['name'])->toBe('Outbound Calls');
});

it('failed() surfaces the real reason when banking the checkpoint fails', function () {
    // proposed_patch whose RESULT is invalid: applyCheckpoint throws while
    // banking it. The job must carry that real reason onto the message (this
    // is where a permission/role error reaching the platform schema would
    // show up) instead of a generic timeout note.
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => 'Listo, guardé los cambios.',
        'proposed_patch' => [['op' => 'replace', 'path' => '/schema_version', 'value' => 'not-a-version']],
        'change_summary' => 'broken patch',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    $fresh = $message->fresh();
    expect($fresh->status)->toBe('error')
        ->and($fresh->applied_version_id)->toBeNull()
        ->and($fresh->content)->toContain('could not be applied')
        ->and($fresh->content)->toContain('Manifest validation failed');
});

it('failed() marks the message error when there is no checkpointed work', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    expect($message->fresh()->status)->toBe('error');
});

it('gets the longer landing timeout only when flagged as a landing turn', function () {
    expect((new RunBuilderAiJob('bmsg_x', 'crea una app'))->timeout)
        ->toBe(RunBuilderAiJob::DEFAULT_TIMEOUT)
        ->and((new RunBuilderAiJob('bmsg_x', 'quiero una landing', isLanding: true))->timeout)
        ->toBe(RunBuilderAiJob::LANDING_TIMEOUT)
        // The landing cap must stay under supervisor-ai's 600s worker timeout.
        ->and(RunBuilderAiJob::LANDING_TIMEOUT)->toBeLessThan(600);
});

it('failed() bills the usage snapshot of a timed-out turn so its spend is attributed', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
        'usage' => usage_snapshot(),
    ]);

    expect(AiUsageEvent::where('app_id', $this->testApp->id)->where('module', 'builder')->count())->toBe(0);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    $event = AiUsageEvent::where('app_id', $this->testApp->id)->where('module', 'builder')->first();
    expect($event)->not->toBeNull()
        ->and($event->model)->toBe('anthropic/claude-opus-5')
        ->and($event->input_tokens)->toBe(1200)
        ->and($event->output_tokens)->toBe(340)
        ->and($event->cache_read_tokens)->toBe(800)
        ->and($event->conversation_id)->toBe($this->conversation->id)
        // The snapshot is flipped to recorded so a later failed() can't re-bill it.
        ->and($message->fresh()->usage['recorded'])->toBeTrue();
});

it('failed() does not double-bill a turn whose usage was already recorded', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
        'usage' => usage_snapshot(['recorded' => true]),
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    expect(AiUsageEvent::where('app_id', $this->testApp->id)->where('module', 'builder')->count())->toBe(0);
});

it('failed() records no usage event when the turn died before any round-trip', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('timed out'));

    expect(AiUsageEvent::where('app_id', $this->testApp->id)->where('module', 'builder')->count())->toBe(0)
        ->and($message->fresh()->status)->toBe('error');
});

it('failed() leaves an already-completed turn untouched', function () {
    $message = BuilderMessage::create([
        'conversation_id' => $this->conversation->id,
        'role' => 'assistant',
        'status' => 'applied',
        'content' => 'done',
    ]);

    (new RunBuilderAiJob($message->id, 'crea una app'))->failed(new RuntimeException('late timeout'));

    expect($message->fresh()->status)->toBe('applied');
});
