<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * A user turn and its assistant placeholder are persisted in the same request,
 * so they routinely share a created_at second — and the transcript sorted by
 * created_at ALONE could surface the reply above the prompt that triggered it
 * (observed in the builder chat). The relation tie-breaks by id (a time-ordered
 * ULID = insertion order), so the earlier-inserted user turn always wins the tie.
 */
it('keeps a user turn before its same-timestamp assistant reply', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $app = App::factory()->create(['user_id' => $user->id, 'visibility' => 'private']);
    $conversation = BuilderConversation::create([
        'app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active',
    ]);

    // Freeze the clock so both rows carry the IDENTICAL created_at — the tie the
    // ordering has to resolve. Their ULID ids still differ by insertion order.
    Carbon::setTestNow(now());

    $userMsg = BuilderMessage::create([
        'conversation_id' => $conversation->id, 'role' => 'user', 'content' => 'crea un dashboard',
    ]);
    $assistantMsg = BuilderMessage::create([
        'conversation_id' => $conversation->id, 'role' => 'assistant', 'content' => 'Creé el dashboard…', 'status' => 'applied',
    ]);

    Carbon::setTestNow();

    expect($userMsg->created_at->equalTo($assistantMsg->created_at))->toBeTrue()
        ->and($userMsg->id)->toBeLessThan($assistantMsg->id); // insertion order

    $ordered = $conversation->refresh()->messages->pluck('role')->all();
    expect($ordered)->toBe(['user', 'assistant']);
});
