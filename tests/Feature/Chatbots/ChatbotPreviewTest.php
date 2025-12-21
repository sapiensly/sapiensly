<?php

use App\Enums\ChatbotStatus;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create(['user_id' => $this->user->id]);
    $this->chatbot = Chatbot::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $this->agent->id,
        'status' => ChatbotStatus::Active,
    ]);
});

describe('preview init', function () {
    it('creates a new preview session and conversation', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );

        $response->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'messages',
            ]);

        $this->assertDatabaseHas('widget_sessions', [
            'chatbot_id' => $this->chatbot->id,
            'visitor_email' => 'preview-'.$this->user->id.'@preview.local',
        ]);

        $this->assertDatabaseHas('widget_conversations', [
            'chatbot_id' => $this->chatbot->id,
            'id' => $response->json('conversation_id'),
        ]);
    });

    it('returns existing conversation if one exists', function () {
        // First init
        $response1 = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );

        $conversationId1 = $response1->json('conversation_id');

        // Second init should return the same conversation
        $response2 = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );

        expect($response2->json('conversation_id'))->toBe($conversationId1);
    });

    it('returns existing messages', function () {
        // Create session and conversation with messages
        $session = WidgetSession::factory()->create([
            'chatbot_id' => $this->chatbot->id,
            'visitor_email' => 'preview-'.$this->user->id.'@preview.local',
        ]);

        $conversation = WidgetConversation::factory()->create([
            'chatbot_id' => $this->chatbot->id,
            'widget_session_id' => $session->id,
            'is_resolved' => false,
        ]);

        WidgetMessage::factory()->create([
            'widget_conversation_id' => $conversation->id,
            'content' => 'Test message',
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );

        $response->assertOk();
        expect($response->json('messages'))->toHaveCount(1);
        expect($response->json('messages.0.content'))->toBe('Test message');
    });

    it('returns 403 for chatbots user cannot access', function () {
        $otherUser = User::factory()->create();
        $otherChatbot = Chatbot::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $otherChatbot)
        );

        $response->assertForbidden();
    });
});

describe('preview send', function () {
    beforeEach(function () {
        // Initialize preview to get conversation
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );
        $this->conversationId = $response->json('conversation_id');
    });

    it('creates a user message', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => $this->conversationId,
                'content' => 'Hello, chatbot!',
            ]
        );

        $response->assertCreated()
            ->assertJsonStructure([
                'message_id',
                'role',
                'content',
                'created_at',
            ]);

        expect($response->json('role'))->toBe('user');
        expect($response->json('content'))->toBe('Hello, chatbot!');

        $this->assertDatabaseHas('widget_messages', [
            'widget_conversation_id' => $this->conversationId,
            'content' => 'Hello, chatbot!',
        ]);
    });

    it('validates content is required', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => $this->conversationId,
            ]
        );

        $response->assertUnprocessable();
    });

    it('validates content max length', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => $this->conversationId,
                'content' => str_repeat('a', 4001),
            ]
        );

        $response->assertUnprocessable();
    });

    it('returns 404 for invalid conversation', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => 'invalid-id',
                'content' => 'Hello!',
            ]
        );

        $response->assertNotFound();
    });

    it('returns 403 for other users conversations', function () {
        $otherUser = User::factory()->create();

        // Create a session for the other user
        $session = WidgetSession::factory()->create([
            'chatbot_id' => $this->chatbot->id,
            'visitor_email' => 'preview-'.$otherUser->id.'@preview.local',
        ]);

        $conversation = WidgetConversation::factory()->create([
            'chatbot_id' => $this->chatbot->id,
            'widget_session_id' => $session->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => $conversation->id,
                'content' => 'Hello!',
            ]
        );

        $response->assertForbidden();
    });
});

describe('preview stream', function () {
    beforeEach(function () {
        // Initialize preview to get conversation
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );
        $this->conversationId = $response->json('conversation_id');
    });

    it('returns 400 when no message to respond to', function () {
        $response = $this->actingAs($this->user)->get(
            route('chatbots.preview.stream', [
                'chatbot' => $this->chatbot->id,
                'conversation' => $this->conversationId,
            ])
        );

        $response->assertBadRequest();
        expect($response->json('error'))->toBe('No message to respond to');
    });

    it('returns 404 for invalid conversation', function () {
        $response = $this->actingAs($this->user)->get(
            route('chatbots.preview.stream', [
                'chatbot' => $this->chatbot->id,
                'conversation' => 'invalid-id',
            ])
        );

        $response->assertNotFound();
    });

    it('returns 400 when chatbot has no agent or team', function () {
        $chatbotNoAgent = Chatbot::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => null,
            'agent_team_id' => null,
        ]);

        // Init and send a message
        $initResponse = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $chatbotNoAgent)
        );

        $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $chatbotNoAgent),
            [
                'conversation_id' => $initResponse->json('conversation_id'),
                'content' => 'Hello!',
            ]
        );

        $response = $this->actingAs($this->user)->get(
            route('chatbots.preview.stream', [
                'chatbot' => $chatbotNoAgent->id,
                'conversation' => $initResponse->json('conversation_id'),
            ])
        );

        $response->assertBadRequest();
        expect($response->json('error'))->toBe('No agent or team configured for this chatbot');
    });

    it('returns existing response as stream when already responded', function () {
        // Send a message
        $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => $this->conversationId,
                'content' => 'Hello!',
            ]
        );

        // Manually create an assistant response (simulating a completed stream)
        WidgetMessage::factory()->assistant()->create([
            'widget_conversation_id' => $this->conversationId,
            'content' => 'Hi there! How can I help you?',
        ]);

        // Stream should return the existing response
        $response = $this->actingAs($this->user)->get(
            route('chatbots.preview.stream', [
                'chatbot' => $this->chatbot->id,
                'conversation' => $this->conversationId,
            ])
        );

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toContain('text/event-stream');
    });
});

describe('preview clear', function () {
    beforeEach(function () {
        // Initialize preview to get conversation
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.init', $this->chatbot)
        );
        $this->conversationId = $response->json('conversation_id');

        // Send a message
        $this->actingAs($this->user)->postJson(
            route('chatbots.preview.send', $this->chatbot),
            [
                'conversation_id' => $this->conversationId,
                'content' => 'Hello!',
            ]
        );
    });

    it('clears conversation and creates a new one', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.clear', $this->chatbot),
            [
                'conversation_id' => $this->conversationId,
            ]
        );

        $response->assertOk()
            ->assertJsonStructure([
                'conversation_id',
                'messages',
            ]);

        // New conversation should be different
        expect($response->json('conversation_id'))->not->toBe($this->conversationId);
        expect($response->json('messages'))->toBeEmpty();

        // Old conversation should be resolved
        $oldConversation = WidgetConversation::find($this->conversationId);
        expect($oldConversation->is_resolved)->toBeTrue();
        expect($oldConversation->messages)->toBeEmpty();
    });

    it('requires conversation_id', function () {
        $response = $this->actingAs($this->user)->postJson(
            route('chatbots.preview.clear', $this->chatbot),
            []
        );

        $response->assertUnprocessable();
    });
});
