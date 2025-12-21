<?php

use App\Enums\ChatbotStatus;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create([
        'user_id' => $this->user->id,
        'status' => 'active',
    ]);
    $this->chatbot = Chatbot::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $this->agent->id,
        'status' => ChatbotStatus::Active,
    ]);
    $this->token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'Test Token',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat', 'feedback'],
    ]);
    $this->session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => 'test-session-token',
    ]);
    $this->conversation = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'widget_session_id' => $this->session->id,
    ]);
});

describe('feedback', function () {
    it('submits rating for conversation', function () {
        $response = $this->postJson("/api/widget/v1/conversations/{$this->conversation->id}/feedback", [
            'rating' => 5,
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'conversation_id' => $this->conversation->id,
                'rating' => 5,
            ]);

        $this->assertDatabaseHas('widget_conversations', [
            'id' => $this->conversation->id,
            'rating' => 5,
        ]);
    });

    it('submits rating with feedback text', function () {
        $response = $this->postJson("/api/widget/v1/conversations/{$this->conversation->id}/feedback", [
            'rating' => 4,
            'feedback' => 'Very helpful!',
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'rating' => 4,
                'feedback' => 'Very helpful!',
            ]);
    });

    it('marks conversation as resolved', function () {
        $response = $this->postJson("/api/widget/v1/conversations/{$this->conversation->id}/feedback", [
            'rating' => 5,
            'is_resolved' => true,
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertOk()
            ->assertJson([
                'is_resolved' => true,
            ]);

        expect($this->conversation->fresh()->is_resolved)->toBeTrue();
    });

    it('validates rating range', function () {
        $response = $this->postJson("/api/widget/v1/conversations/{$this->conversation->id}/feedback", [
            'rating' => 6,
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertUnprocessable();
    });

    it('requires rating', function () {
        $response = $this->postJson("/api/widget/v1/conversations/{$this->conversation->id}/feedback", [
            'feedback' => 'Great!',
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertUnprocessable();
    });

    it('returns 404 for non-existent conversation', function () {
        $response = $this->postJson('/api/widget/v1/conversations/non-existent/feedback', [
            'rating' => 5,
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertNotFound();
    });

    it('cannot submit feedback for conversation from different chatbot', function () {
        $otherChatbot = Chatbot::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
            'status' => ChatbotStatus::Active,
        ]);

        $otherConversation = WidgetConversation::create([
            'chatbot_id' => $otherChatbot->id,
            'widget_session_id' => $this->session->id,
        ]);

        $response = $this->postJson("/api/widget/v1/conversations/{$otherConversation->id}/feedback", [
            'rating' => 5,
        ], [
            'Authorization' => "Bearer {$this->token->token}",
        ]);

        $response->assertNotFound();
    });
});
