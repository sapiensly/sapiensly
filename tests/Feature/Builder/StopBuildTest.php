<?php

use App\Jobs\ResolveStoppedBuildJob;
use App\Jobs\RunBuilderAiJob;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use Illuminate\Support\Facades\Queue;

/**
 * The Detener button: a cooperative stop flag that the streaming loop, the
 * autonomous/resume chain and queued auto-turns all honor — a stopped build
 * stays stopped (and keeps its banked progress) until the user speaks again.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $this->cancellation = app(BuilderCancellation::class);
});

it('round-trips the stop flag scoped to the conversation owner', function () {
    expect($this->cancellation->requested($this->conv))->toBeFalse();

    $this->cancellation->request($this->conv);
    expect($this->cancellation->requested($this->conv))->toBeTrue();

    $this->cancellation->clear($this->conv);
    expect($this->cancellation->requested($this->conv))->toBeFalse();
});

it('refuses to resume after a timeout once stop was requested', function () {
    Queue::fake();
    $this->conv->update(['build_plan' => ['schema' => 1, 'goal' => null, 'status' => 'active', 'steps' => [
        ['id' => 'stp_a', 'title' => 'A', 'detail' => null, 'status' => 'in_progress', 'applied_version_id' => null, 'version_number' => null, 'closed_by_summary' => null, 'error' => null],
    ]]]);
    $finished = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => '',
        'status' => 'applied', 'applied_version_id' => 'apv_x',
    ]);

    $this->cancellation->request($this->conv);

    expect(app(BuilderAiService::class)->resumeAfterTimeout($finished, null, 0, 2))->toBeFalse();
    Queue::assertNotPushed(RunBuilderAiJob::class);
});

it('refuses to continue the autonomous chain once stop was requested', function () {
    Queue::fake();
    $this->conv->update(['build_plan' => ['schema' => 1, 'goal' => null, 'status' => 'active', 'steps' => [
        ['id' => 'stp_a', 'title' => 'A', 'detail' => null, 'status' => 'done', 'applied_version_id' => 'apv_x', 'version_number' => 2, 'closed_by_summary' => 'ok', 'error' => null],
        ['id' => 'stp_b', 'title' => 'B', 'detail' => null, 'status' => 'pending', 'applied_version_id' => null, 'version_number' => null, 'closed_by_summary' => null, 'error' => null],
    ]]]);
    $finished = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => '',
        'status' => 'applied', 'applied_version_id' => 'apv_x', 'plan_step_ids' => ['stp_a'],
    ]);

    $this->cancellation->request($this->conv);

    app(BuilderAiService::class)->continueAutonomously($finished, 5, null);
    Queue::assertNotPushed(RunBuilderAiJob::class);
});

it('aborts an auto-queued turn at pickup without spending a token', function () {
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => '',
        'status' => 'streaming',
    ]);
    $this->cancellation->request($this->conv);

    $service = Mockery::mock(BuilderAiService::class);
    $service->shouldNotReceive('streamMessage');

    (new RunBuilderAiJob($placeholder->id, 'continúa', autoQueued: true))->handle($service);

    expect($placeholder->fresh()->status)->toBe('none')
        ->and($placeholder->fresh()->content)->toContain('detenido');
});

it('exposes the stop endpoint and clears the flag on the next message', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/stop", ['conversation_id' => $this->conv->id])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($this->cancellation->requested($this->conv))->toBeTrue();

    // A new user message re-arms the machinery.
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages", [
            'conversation_id' => $this->conv->id,
            'message' => 'sigue con la página',
        ])
        ->assertOk();

    expect($this->cancellation->requested($this->conv))->toBeFalse();
    Queue::assertPushed(RunBuilderAiJob::class);
});

it('backs Detener with a resolver job when a turn is still streaming', function () {
    Queue::fake();
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant',
        'content' => 'Modelando 4 objeto(s)…', 'status' => 'streaming',
    ]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/stop", ['conversation_id' => $this->conv->id])
        ->assertOk();

    Queue::assertPushed(ResolveStoppedBuildJob::class, function (ResolveStoppedBuildJob $job) use ($placeholder) {
        return $job->placeholderId === $placeholder->id;
    });
});

it('resolver closes a DEAD turn stuck streaming, keeping the narration', function () {
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant',
        'content' => 'Ajustando el spec…', 'status' => 'streaming',
    ]);

    (new ResolveStoppedBuildJob($this->conv->id, $placeholder->id))->handle();

    $placeholder->refresh();
    expect($placeholder->status)->toBe('none')
        ->and($placeholder->content)->toContain('Ajustando el spec'); // narration kept

    $stop = BuilderMessage::where('conversation_id', $this->conv->id)
        ->where('id', '!=', $placeholder->id)->first();
    expect($stop)->not->toBeNull()
        ->and($stop->content)->toContain('detenido por el usuario');
});

it('resolver no-ops when a LIVE turn already finalized within the grace', function () {
    // The live turn banked and closed itself → status left streaming/pending.
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant',
        'content' => 'Listo', 'status' => 'applied',
    ]);

    (new ResolveStoppedBuildJob($this->conv->id, $placeholder->id))->handle();

    // Untouched, and no extra stop message appended.
    expect($placeholder->fresh()->status)->toBe('applied')
        ->and(BuilderMessage::where('conversation_id', $this->conv->id)->count())->toBe(1);
});

it('rejects stopping a conversation the caller does not own', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->postJson("/apps/{$this->testApp->id}/builder/stop", ['conversation_id' => $this->conv->id])
        ->assertStatus(403);
});

it('resolver bills the dead turn it closes, so a stopped build keeps its cost', function () {
    // Three places close a dead turn and only RunBuilderAiJob::failed() used to
    // pay for it. A hard-killed worker never reaches failed(), so a build
    // stopped here lost the tokens it had already spent — get_build_cost
    // reported the app cheaper than it was, and flagged the turn as
    // unattributed without being able to recover it.
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => 'Añadí el hero…',
        'usage' => [
            'model' => 'anthropic/claude-opus-5',
            'prompt_tokens' => 1200,
            'completion_tokens' => 340,
            'cache_read_input_tokens' => 800,
            'recorded' => false,
        ],
    ]);

    (new ResolveStoppedBuildJob($this->conv->id, $placeholder->id))->handle();

    $event = AiUsageEvent::query()
        ->where('app_id', $this->testApp->id)->where('module', 'builder')->first();

    expect($event)->not->toBeNull()
        ->and($event->model)->toBe('anthropic/claude-opus-5')
        ->and($event->input_tokens)->toBe(1200)
        ->and($event->output_tokens)->toBe(340)
        ->and($event->conversation_id)->toBe($this->conv->id)
        // Flipped, so the reaper can't bill the same turn a second time.
        ->and($placeholder->fresh()->usage['recorded'])->toBeTrue()
        // And it still did its actual job.
        ->and($placeholder->fresh()->status)->toBe('none');
});

it('resolver does not re-bill a turn whose usage was already recorded', function () {
    $placeholder = BuilderMessage::create([
        'conversation_id' => $this->conv->id,
        'role' => 'assistant',
        'status' => 'streaming',
        'content' => '',
        'usage' => ['model' => 'anthropic/claude-opus-5', 'prompt_tokens' => 10, 'recorded' => true],
    ]);

    (new ResolveStoppedBuildJob($this->conv->id, $placeholder->id))->handle();

    expect(AiUsageEvent::query()->where('module', 'builder')->count())->toBe(0);
});
