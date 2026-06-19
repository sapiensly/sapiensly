<?php

use App\Models\Chatbot;
use App\Models\User;
use App\Services\BotFlows\BotFlowScaffolder;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('scaffolding returns a generated definition for the canvas', function () {
    $fixed = [
        'nodes' => [['id' => 'start', 'type' => 'start', 'data' => ['trigger' => 'conversation_start']]],
        'edges' => [],
    ];

    $this->mock(BotFlowScaffolder::class)
        ->shouldReceive('scaffold')
        ->once()
        ->andReturn($fixed);

    $chatbot = Chatbot::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chatbots.flow.scaffold', $chatbot), ['description' => 'a support bot that triages and answers FAQs'])
        ->assertOk()
        ->assertJson(['definition' => $fixed]);
});

test('scaffolding requires a description', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chatbots.flow.scaffold', $chatbot), [])
        ->assertStatus(422);
});

test('a foreign user cannot scaffold another bots flow', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson(route('chatbots.flow.scaffold', $chatbot), ['description' => 'x'])
        ->assertForbidden();
});

test('a conversational turn returns the reply, spec and definition', function () {
    $result = [
        'reply' => 'Added a welcome message.',
        'spec' => ['welcome_message' => 'Hi', 'roles' => ['triage'], 'menu' => null],
        'definition' => ['nodes' => [['id' => 'start', 'type' => 'start', 'data' => []]], 'edges' => []],
    ];

    $this->mock(BotFlowScaffolder::class)
        ->shouldReceive('converse')
        ->once()
        ->andReturn($result);

    $chatbot = Chatbot::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chatbots.flow.assistant', $chatbot), [
            'messages' => [['role' => 'user', 'content' => 'add a welcome message']],
            'spec' => null,
        ])
        ->assertOk()
        ->assertJson($result);
});

test('a conversational turn requires at least one message', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();

    $this->actingAs($this->user)
        ->postJson(route('chatbots.flow.assistant', $chatbot), ['messages' => []])
        ->assertStatus(422);
});

test('a foreign user cannot drive another bots assistant', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->postJson(route('chatbots.flow.assistant', $chatbot), [
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ])
        ->assertForbidden();
});
