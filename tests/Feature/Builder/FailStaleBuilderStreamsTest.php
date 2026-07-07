<?php

use App\Events\Builder\BuilderStreamComplete;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
});

function staleBuilderMessage(BuilderConversation $conv, string $status): BuilderMessage
{
    $m = BuilderMessage::create([
        'conversation_id' => $conv->id,
        'role' => 'assistant',
        'content' => '',
        'status' => $status,
    ]);
    BuilderMessage::where('id', $m->id)->update(['created_at' => now()->subHour()]);

    return $m;
}

it('flips a narration-less stale placeholder to the error in place', function () {
    $streaming = staleBuilderMessage($this->conv, 'streaming');
    $pending = staleBuilderMessage($this->conv, 'pending');

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    expect($streaming->fresh()->status)->toBe('error')
        ->and($streaming->fresh()->content)->not->toBeEmpty()
        ->and($pending->fresh()->status)->toBe('error');
});

it('keeps the progress narration and appends the error as a NEW message', function () {
    $placeholder = staleBuilderMessage($this->conv, 'streaming');
    BuilderMessage::where('id', $placeholder->id)->update([
        'content' => "🔌 Localizando la fuente…\n📦 Modelando 4 objeto(s)…",
    ]);

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    $placeholder->refresh();
    expect($placeholder->status)->toBe('none')
        ->and($placeholder->content)->toContain('Modelando 4 objeto(s)');

    $error = BuilderMessage::where('conversation_id', $this->conv->id)
        ->where('status', 'error')->first();
    expect($error)->not->toBeNull()
        ->and($error->id)->not->toBe($placeholder->id)
        ->and($error->content)->toContain('interrupted');
});

it('resolves a narrated `none` placeholder orphaned by a half-finalized turn', function () {
    // Express killed between flipping the placeholder to `none` and inserting
    // the report: the placeholder is `none` with narration and no report
    // behind it — invisible to the pending/streaming sweep.
    $orphan = staleBuilderMessage($this->conv, 'none');
    BuilderMessage::where('id', $orphan->id)->update([
        'content' => "Localizando la fuente…\nAjustando el spec y redactando los insights…",
    ]);

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    $orphan->refresh();
    expect($orphan->status)->toBe('none')
        ->and($orphan->content)->toContain('Ajustando el spec');

    $error = BuilderMessage::where('conversation_id', $this->conv->id)
        ->where('status', 'error')->first();
    expect($error)->not->toBeNull()
        ->and($error->id)->not->toBe($orphan->id)
        ->and($error->content)->toContain('interrupted');
});

it('leaves a `none` placeholder that already has its report message alone', function () {
    $placeholder = staleBuilderMessage($this->conv, 'none');
    BuilderMessage::where('id', $placeholder->id)->update(['content' => 'progreso…']);
    // The report DID land — a later message → not an orphan.
    BuilderMessage::create([
        'conversation_id' => $this->conv->id,
        'role' => 'assistant',
        'content' => 'Dashboard listo',
        'status' => 'applied',
    ]);

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    expect(BuilderMessage::where('conversation_id', $this->conv->id)->where('status', 'error')->count())->toBe(0)
        ->and($placeholder->fresh()->status)->toBe('none');
});

it('broadcasts completions so the open UI resolves without a reload', function () {
    Event::fake([BuilderStreamComplete::class]);
    $placeholder = staleBuilderMessage($this->conv, 'streaming');
    BuilderMessage::where('id', $placeholder->id)->update(['content' => 'progreso…']);

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    // Two completions: the kept narration + the appended error message.
    Event::assertDispatched(BuilderStreamComplete::class, 2);
});

it('leaves live streams and finished builder messages untouched', function () {
    $live = BuilderMessage::create([
        'conversation_id' => $this->conv->id,
        'role' => 'assistant',
        'content' => '',
        'status' => 'streaming',
    ]);
    $done = BuilderMessage::create([
        'conversation_id' => $this->conv->id,
        'role' => 'assistant',
        'content' => 'Listo',
        'status' => 'applied',
    ]);
    BuilderMessage::where('id', $done->id)->update(['created_at' => now()->subHours(3)]);

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    expect($live->fresh()->status)->toBe('streaming');
    expect($done->fresh()->status)->toBe('applied');
});

it('writes the interruption note in the app owner\'s language', function () {
    $spanish = User::factory()->create(['locale' => 'es', 'email_verified_at' => now()]);
    $app = App::factory()->create(['user_id' => $spanish->id, 'visibility' => 'private']);
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $spanish->id, 'status' => 'active']);
    $stale = staleBuilderMessage($conv, 'streaming');

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    expect($stale->fresh()->status)->toBe('error')
        ->and($stale->fresh()->content)->toContain('interrump');
});
