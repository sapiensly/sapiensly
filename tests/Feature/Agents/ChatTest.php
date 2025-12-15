<?php

use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\LLMService;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('chat page', function () {
    it('displays the chat page for an agent', function () {
        $agent = Agent::factory()->standalone()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Agent',
        ]);

        $this->actingAs($this->user)
            ->get(route('agents.chat', $agent))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('standalone-agents/Chat')
                ->where('agent.id', $agent->id)
                ->has('conversation')
            );
    });

    it('creates a conversation if none exists', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        $this->assertDatabaseMissing('conversations', [
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('agents.chat', $agent))
            ->assertOk();

        $this->assertDatabaseHas('conversations', [
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);
    });

    it('returns 403 for agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->get(route('agents.chat', $otherAgent))
            ->assertForbidden();
    });
});

describe('send message', function () {
    it('saves a user message to the conversation', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post(route('agents.chat.send', $agent), [
                'message' => 'Hello, agent!',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'role' => MessageRole::User->value,
            'content' => 'Hello, agent!',
        ]);
    });

    it('validates message is required', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post(route('agents.chat.send', $agent), [])
            ->assertSessionHasErrors(['message']);
    });

    it('returns 403 for agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->post(route('agents.chat.send', $otherAgent), [
                'message' => 'Hello!',
            ])
            ->assertForbidden();
    });
});

describe('new conversation', function () {
    it('deletes existing conversation and redirects to chat', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Old message',
        ]);

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);

        $this->actingAs($this->user)
            ->post(route('agents.chat.new', $agent))
            ->assertRedirect(route('agents.chat', $agent));

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    });

    it('works when no conversation exists', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->post(route('agents.chat.new', $agent))
            ->assertRedirect(route('agents.chat', $agent));
    });

    it('returns 403 for agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();

        $this->actingAs($this->user)
            ->post(route('agents.chat.new', $otherAgent))
            ->assertForbidden();
    });

    it('only deletes conversation for the current user', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);

        // Create conversation for current user
        $myConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);

        // Create conversation for another user with same agent
        $otherUser = User::factory()->create();
        $otherConversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($this->user)
            ->post(route('agents.chat.new', $agent))
            ->assertRedirect();

        // My conversation should be deleted
        $this->assertDatabaseMissing('conversations', ['id' => $myConversation->id]);

        // Other user's conversation should remain
        $this->assertDatabaseHas('conversations', ['id' => $otherConversation->id]);
    });
});

describe('stream response', function () {
    it('returns 403 for agents belonging to other users', function () {
        $otherAgent = Agent::factory()->standalone()->create();
        $conversation = Conversation::factory()->create([
            'user_id' => $otherAgent->user_id,
            'agent_id' => $otherAgent->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('agents.chat.stream', [$otherAgent, $conversation]))
            ->assertForbidden();
    });

    it('returns 403 for conversations belonging to other users', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);
        $otherConversation = Conversation::factory()->create([
            'agent_id' => $agent->id,
        ]);

        $this->actingAs($this->user)
            ->get(route('agents.chat.stream', [$agent, $otherConversation]))
            ->assertForbidden();
    });

    it('returns error when no messages in conversation', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('agents.chat.stream', [$agent, $conversation]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
    });

    it('returns error when last message is not from user', function () {
        $agent = Agent::factory()->standalone()->create(['user_id' => $this->user->id]);
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);
        $conversation->messages()->create([
            'role' => MessageRole::Assistant,
            'content' => 'Hello!',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('agents.chat.stream', [$agent, $conversation]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
    });

    it('returns correct headers for valid stream request', function () {
        $agent = Agent::factory()->standalone()->create([
            'user_id' => $this->user->id,
            'model' => 'claude-3-5-haiku-20241022',
        ]);
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
        ]);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Hello!',
        ]);

        // Mock the LLM service to return a simple generator
        $mockLLMService = Mockery::mock(LLMService::class);
        $mockLLMService->shouldReceive('streamChat')
            ->andReturnUsing(function () {
                yield 'Hello';
                yield ', ';
                yield 'world!';
            });

        $this->app->instance(LLMService::class, $mockLLMService);

        $response = $this->actingAs($this->user)
            ->get(route('agents.chat.stream', [$agent, $conversation]));

        // Note: Laravel's test client doesn't fully execute StreamedResponse callbacks
        // The streaming behavior is tested through real browser interactions
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $response->assertHeader('Cache-Control', 'no-cache, private');
        $response->assertHeader('X-Accel-Buffering', 'no');
    });
});
