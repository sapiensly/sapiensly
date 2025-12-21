<?php

use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
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
});

describe('conversations', function () {
    describe('store', function () {
        it('creates a new conversation', function () {
            $response = $this->postJson('/api/widget/v1/conversations', [
                'session_token' => $this->session->session_token,
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertCreated()
                ->assertJsonStructure([
                    'conversation_id',
                    'created_at',
                ]);

            $this->assertDatabaseHas('widget_conversations', [
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $this->session->id,
            ]);
        });

        it('creates conversation with initial message', function () {
            $response = $this->postJson('/api/widget/v1/conversations', [
                'session_token' => $this->session->session_token,
                'initial_message' => 'Hello, I need help!',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertCreated()
                ->assertJsonStructure([
                    'conversation_id',
                    'initial_message' => [
                        'id',
                        'role',
                        'content',
                    ],
                ]);

            $conversationId = $response->json('conversation_id');
            $this->assertDatabaseHas('widget_messages', [
                'widget_conversation_id' => $conversationId,
                'role' => MessageRole::User,
                'content' => 'Hello, I need help!',
            ]);
        });

        it('returns 404 for invalid session token', function () {
            $response = $this->postJson('/api/widget/v1/conversations', [
                'session_token' => 'invalid-token',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertNotFound();
        });
    });

    describe('messages', function () {
        it('returns conversation messages', function () {
            $conversation = WidgetConversation::create([
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $this->session->id,
            ]);

            WidgetMessage::create([
                'widget_conversation_id' => $conversation->id,
                'role' => MessageRole::User,
                'content' => 'Hello!',
            ]);

            WidgetMessage::create([
                'widget_conversation_id' => $conversation->id,
                'role' => MessageRole::Assistant,
                'content' => 'Hi there!',
            ]);

            $response = $this->getJson("/api/widget/v1/conversations/{$conversation->id}/messages", [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertOk()
                ->assertJsonCount(2, 'messages')
                ->assertJsonStructure([
                    'conversation_id',
                    'messages' => [
                        '*' => ['id', 'role', 'content', 'created_at'],
                    ],
                ]);
        });

        it('returns 404 for non-existent conversation', function () {
            $response = $this->getJson('/api/widget/v1/conversations/non-existent/messages', [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertNotFound();
        });
    });

    describe('sendMessage', function () {
        it('sends a message to conversation', function () {
            $conversation = WidgetConversation::create([
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $this->session->id,
            ]);

            $response = $this->postJson("/api/widget/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Hello, this is my message',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertCreated()
                ->assertJsonStructure([
                    'message_id',
                    'role',
                    'content',
                    'created_at',
                    'stream_url',
                ]);

            $this->assertDatabaseHas('widget_messages', [
                'widget_conversation_id' => $conversation->id,
                'content' => 'Hello, this is my message',
            ]);
        });

        it('validates message content', function () {
            $conversation = WidgetConversation::create([
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $this->session->id,
            ]);

            $response = $this->postJson("/api/widget/v1/conversations/{$conversation->id}/messages", [
                'content' => '',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            $response->assertUnprocessable();
        });

        it('increments message count', function () {
            $conversation = WidgetConversation::create([
                'chatbot_id' => $this->chatbot->id,
                'widget_session_id' => $this->session->id,
                'message_count' => 0,
            ]);

            $this->postJson("/api/widget/v1/conversations/{$conversation->id}/messages", [
                'content' => 'Hello!',
            ], [
                'Authorization' => "Bearer {$this->token->token}",
            ]);

            expect($conversation->fresh()->message_count)->toBe(1);
        });
    });
});
