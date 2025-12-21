<?php

use App\Enums\ChatbotStatus;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\User;
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
});

describe('sessions', function () {
    describe('store', function () {
        it('creates a new session', function () {
            $response = $this->postJson('/api/widget/v1/sessions', [], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertCreated()
                ->assertJsonStructure([
                    'session_id',
                    'session_token',
                    'created_at',
                ]);

            $this->assertDatabaseHas('widget_sessions', [
                'chatbot_id' => $this->chatbot->id,
            ]);
        });

        it('creates a session with visitor info', function () {
            $response = $this->postJson('/api/widget/v1/sessions', [
                'visitor_email' => 'test@example.com',
                'visitor_name' => 'Test User',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertCreated();

            $this->assertDatabaseHas('widget_sessions', [
                'chatbot_id' => $this->chatbot->id,
                'visitor_email' => 'test@example.com',
                'visitor_name' => 'Test User',
            ]);
        });

        it('returns 401 without token', function () {
            $response = $this->postJson('/api/widget/v1/sessions');

            $response->assertUnauthorized();
        });

        it('returns 401 with invalid token', function () {
            $response = $this->postJson('/api/widget/v1/sessions', [], [
                'Authorization' => 'Bearer invalid-token',
            ]);

            $response->assertUnauthorized();
        });
    });

    describe('update', function () {
        it('updates session with visitor info', function () {
            $session = WidgetSession::create([
                'chatbot_id' => $this->chatbot->id,
                'session_token' => 'test-session-token',
            ]);

            $response = $this->patchJson("/api/widget/v1/sessions/{$session->id}", [
                'visitor_email' => 'updated@example.com',
                'visitor_name' => 'Updated Name',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertOk()
                ->assertJson([
                    'visitor_email' => 'updated@example.com',
                    'visitor_name' => 'Updated Name',
                ]);
        });

        it('updates session by session token', function () {
            $session = WidgetSession::create([
                'chatbot_id' => $this->chatbot->id,
                'session_token' => 'test-session-token',
            ]);

            $response = $this->patchJson('/api/widget/v1/sessions/test-session-token', [
                'visitor_email' => 'updated@example.com',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertOk();
        });

        it('returns 404 for non-existent session', function () {
            $response = $this->patchJson('/api/widget/v1/sessions/non-existent', [
                'visitor_email' => 'test@example.com',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertNotFound();
        });

        it('cannot update session from different chatbot', function () {
            $otherChatbot = Chatbot::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => ChatbotStatus::Active,
            ]);

            $session = WidgetSession::create([
                'chatbot_id' => $otherChatbot->id,
                'session_token' => 'other-session-token',
            ]);

            $response = $this->patchJson("/api/widget/v1/sessions/{$session->id}", [
                'visitor_email' => 'test@example.com',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertNotFound();
        });
    });
});
