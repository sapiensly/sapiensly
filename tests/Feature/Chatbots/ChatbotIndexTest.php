<?php

use App\Models\Chatbot;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('the AI Bots index renders with tenant-scoped conversation and session counts', function () {
    $chatbot = Chatbot::factory()->forUser($this->user)->create();

    WidgetSession::factory()->count(2)->create(['chatbot_id' => $chatbot->id]);
    WidgetConversation::factory()->count(3)->create(['chatbot_id' => $chatbot->id]);

    $this->actingAs($this->user)
        ->get(route('chatbots.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chatbots/Index')
            ->where('chatbots.data.0.id', $chatbot->id)
            ->where('chatbots.data.0.sessions_count', 2)
            ->where('chatbots.data.0.conversations_count', 3));
});
