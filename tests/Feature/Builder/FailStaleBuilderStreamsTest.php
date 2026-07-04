<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;

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

it('marks builder messages stuck streaming/pending past the cap as errors', function () {
    $streaming = staleBuilderMessage($this->conv, 'streaming');
    $pending = staleBuilderMessage($this->conv, 'pending');

    $this->artisan('builder:fail-stale-streams')->assertSuccessful();

    expect($streaming->fresh()->status)->toBe('error')
        ->and($streaming->fresh()->content)->not->toBeEmpty()
        ->and($pending->fresh()->status)->toBe('error');
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
