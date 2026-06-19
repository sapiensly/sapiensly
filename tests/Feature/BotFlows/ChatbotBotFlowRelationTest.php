<?php

use App\Models\BotFlow;
use App\Models\Chatbot;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('a chatbot has one bot flow', function () {
    $chatbot = Chatbot::factory()->create(['user_id' => $this->user->id]);
    $flow = BotFlow::factory()->create([
        'user_id' => $this->user->id,
        'chatbot_id' => $chatbot->id,
    ]);

    expect($chatbot->botFlow)->not->toBeNull()
        ->and($chatbot->botFlow->id)->toBe($flow->id)
        ->and($flow->chatbot->id)->toBe($chatbot->id);
});

test('chatbot_id is unique across bot flows', function () {
    $chatbot = Chatbot::factory()->create(['user_id' => $this->user->id]);
    BotFlow::factory()->create(['user_id' => $this->user->id, 'chatbot_id' => $chatbot->id]);

    expect(fn () => BotFlow::factory()->create([
        'user_id' => $this->user->id,
        'chatbot_id' => $chatbot->id,
    ]))->toThrow(QueryException::class);
});

test('a draft bot flow may have no chatbot', function () {
    $flow = BotFlow::factory()->create(['user_id' => $this->user->id]);

    expect($flow->chatbot_id)->toBeNull()
        ->and($flow->chatbot)->toBeNull();
});
