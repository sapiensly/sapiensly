<?php

use App\Models\Chatbot;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('creating an AI Bot provisions a blank Bot Flow it owns', function () {
    $this->actingAs($this->user)
        ->post(route('chatbots.store'), [
            'name' => 'Support Bot',
            'description' => 'Helps customers',
        ])
        ->assertRedirect();

    $chatbot = Chatbot::where('name', 'Support Bot')->first();

    expect($chatbot)->not->toBeNull()
        ->and($chatbot->agent_id)->toBeNull()
        ->and($chatbot->botFlow)->not->toBeNull()
        ->and($chatbot->botFlow->chatbot_id)->toBe($chatbot->id)
        ->and($chatbot->botFlow->getStartNode())->not->toBeNull();
});

test('the AI Bot flow editor renders and creates a flow on first open', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();

    expect($chatbot->botFlow)->toBeNull();

    $this->actingAs($this->user)
        ->get(route('chatbots.flow.edit', $chatbot))
        ->assertOk();

    expect($chatbot->fresh()->botFlow)->not->toBeNull();
});

test('a foreign user cannot open another bots flow editor', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->get(route('chatbots.flow.edit', $chatbot))
        ->assertForbidden();
});
