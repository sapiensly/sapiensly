<?php

use App\Enums\ChatbotStatus;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Chatbots\CreateChatbotTool;
use App\Mcp\Tools\Chatbots\UpdateChatbotTool;
use App\Models\Chatbot;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('create_chatbot creates a draft with a companion channel and a blank flow', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateChatbotTool::class, ['name' => 'Helpdesk Bot'])
        ->assertOk()
        ->assertSee('Helpdesk Bot')
        ->assertSee('draft')
        ->assertSee('has_flow');

    $chatbot = Chatbot::where('user_id', $this->user->id)->where('name', 'Helpdesk Bot')->first();
    expect($chatbot)->not->toBeNull();
    expect($chatbot->status)->toBe(ChatbotStatus::Draft);
    expect($chatbot->channel_id)->not->toBeNull();
    expect($chatbot->botFlow)->not->toBeNull();
    expect($chatbot->apiTokens()->count())->toBe(1);
});

it('update_chatbot applies a partial update (publish via status)', function () {
    $chatbot = Chatbot::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Original Bot',
        'status' => ChatbotStatus::Draft,
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(UpdateChatbotTool::class, ['chatbot_id' => $chatbot->id, 'status' => 'active'])
        ->assertOk()
        ->assertSee('active')
        ->assertSee('Original Bot');

    $chatbot->refresh();
    expect($chatbot->status)->toBe(ChatbotStatus::Active);
    expect($chatbot->name)->toBe('Original Bot'); // untouched
});

it('update_chatbot will not touch a chatbot outside the caller context', function () {
    $other = Chatbot::factory()->create(); // a different account's chatbot

    SapiensServer::actingAs($this->user)
        ->tool(UpdateChatbotTool::class, ['chatbot_id' => $other->id, 'name' => 'Hijacked'])
        ->assertHasErrors();

    expect($other->fresh()->name)->not->toBe('Hijacked');
});
