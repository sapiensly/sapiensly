<?php

use App\Enums\AgentStatus;
use App\Enums\AgentType;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\AgentTeam;
use App\Models\Conversation;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->team = AgentTeam::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Test Team',
    ]);
});

describe('team chat page', function () {
    test('it displays the team chat page', function () {
        // Create agents for the team
        Agent::factory()->create([
            'user_id' => $this->user->id,
            'agent_team_id' => $this->team->id,
            'type' => AgentType::Triage,
            'name' => 'Triage Bot',
        ]);

        Agent::factory()->create([
            'user_id' => $this->user->id,
            'agent_team_id' => $this->team->id,
            'type' => AgentType::Knowledge,
            'name' => 'Knowledge Bot',
        ]);

        $response = $this->get("/agent-teams/{$this->team->id}/chat");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('agent-teams/Chat')
            ->has('team')
            ->has('conversation')
            ->where('team.id', $this->team->id)
        );
    });

    test('it creates a conversation if none exists', function () {
        expect(Conversation::where('team_id', $this->team->id)->count())->toBe(0);

        $this->get("/agent-teams/{$this->team->id}/chat");

        expect(Conversation::where('team_id', $this->team->id)->count())->toBe(1);
    });

    test('it returns 403 for teams belonging to other users', function () {
        $otherUser = User::factory()->create();
        $otherTeam = AgentTeam::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->get("/agent-teams/{$otherTeam->id}/chat");

        $response->assertStatus(403);
    });
});

describe('send message', function () {
    test('it saves a user message to the conversation', function () {
        $response = $this->post("/agent-teams/{$this->team->id}/messages", [
            'message' => 'Hello, team!',
        ]);

        $response->assertRedirect();

        $conversation = Conversation::where('team_id', $this->team->id)->first();
        expect($conversation)->not->toBeNull();
        expect($conversation->messages()->count())->toBe(1);
        expect($conversation->messages()->first()->content)->toBe('Hello, team!');
        expect($conversation->messages()->first()->role)->toBe(MessageRole::User);
    });

    test('it validates message is required', function () {
        $response = $this->post("/agent-teams/{$this->team->id}/messages", [
            'message' => '',
        ]);

        $response->assertSessionHasErrors('message');
    });

    test('it returns 403 for teams belonging to other users', function () {
        $otherUser = User::factory()->create();
        $otherTeam = AgentTeam::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->post("/agent-teams/{$otherTeam->id}/messages", [
            'message' => 'Hello',
        ]);

        $response->assertStatus(403);
    });
});

describe('new conversation', function () {
    test('it deletes existing conversation and redirects to chat', function () {
        // Create existing conversation with messages
        $conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
        ]);
        $conversation->messages()->create([
            'role' => MessageRole::User,
            'content' => 'Old message',
        ]);

        $response = $this->post("/agent-teams/{$this->team->id}/new-conversation");

        $response->assertRedirect("/agent-teams/{$this->team->id}/chat");
        expect(Conversation::find($conversation->id))->toBeNull();
    });

    test('it works when no conversation exists', function () {
        $response = $this->post("/agent-teams/{$this->team->id}/new-conversation");

        $response->assertRedirect("/agent-teams/{$this->team->id}/chat");
    });

    test('it returns 403 for teams belonging to other users', function () {
        $otherUser = User::factory()->create();
        $otherTeam = AgentTeam::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->post("/agent-teams/{$otherTeam->id}/new-conversation");

        $response->assertStatus(403);
    });

    test('it only deletes the current user conversation', function () {
        $otherUser = User::factory()->create();

        // Create current user's conversation
        $myConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
        ]);

        // Create other user's conversation for same team (shouldn't happen normally)
        $otherConversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'team_id' => $this->team->id,
        ]);

        $this->post("/agent-teams/{$this->team->id}/new-conversation");

        expect(Conversation::find($myConversation->id))->toBeNull();
        expect(Conversation::find($otherConversation->id))->not->toBeNull();
    });
});

describe('triage routing service', function () {
    test('it builds routing tools based on team agents', function () {
        // Create all three agents
        Agent::factory()->create([
            'user_id' => $this->user->id,
            'agent_team_id' => $this->team->id,
            'type' => AgentType::Triage,
            'name' => 'Triage Bot',
        ]);

        Agent::factory()->create([
            'user_id' => $this->user->id,
            'agent_team_id' => $this->team->id,
            'type' => AgentType::Knowledge,
            'name' => 'Knowledge Bot',
        ]);

        Agent::factory()->create([
            'user_id' => $this->user->id,
            'agent_team_id' => $this->team->id,
            'type' => AgentType::Action,
            'name' => 'Action Bot',
            'status' => AgentStatus::Active,
        ]);

        $service = app(\App\Services\TriageRoutingService::class);
        $tools = $service->buildRoutingTools($this->team->fresh());

        // Should have 3 tools: route_to_knowledge, route_to_action, respond_directly
        expect($tools)->toHaveCount(3);

        $toolNames = collect($tools)->map(fn ($t) => $t->name())->all();
        expect($toolNames)->toContain('route_to_knowledge');
        expect($toolNames)->toContain('route_to_action');
        expect($toolNames)->toContain('respond_directly');
    });

    test('it only builds tools for configured agents', function () {
        // Only create triage agent (no knowledge or action)
        Agent::factory()->create([
            'user_id' => $this->user->id,
            'agent_team_id' => $this->team->id,
            'type' => AgentType::Triage,
            'name' => 'Triage Bot',
        ]);

        $service = app(\App\Services\TriageRoutingService::class);
        $tools = $service->buildRoutingTools($this->team->fresh());

        // Should only have respond_directly since no knowledge or action agents
        expect($tools)->toHaveCount(1);

        $toolNames = collect($tools)->map(fn ($t) => $t->name())->all();
        expect($toolNames)->toContain('respond_directly');
        expect($toolNames)->not->toContain('route_to_knowledge');
        expect($toolNames)->not->toContain('route_to_action');
    });
});

describe('team stream endpoint', function () {
    test('it returns 403 for teams belonging to other users', function () {
        $otherUser = User::factory()->create();
        $otherTeam = AgentTeam::factory()->create([
            'user_id' => $otherUser->id,
        ]);
        $conversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'team_id' => $otherTeam->id,
        ]);

        $response = $this->get("/agent-teams/{$otherTeam->id}/stream/{$conversation->id}");

        $response->assertStatus(403);
    });

    test('it returns 403 for conversations belonging to other users', function () {
        $otherUser = User::factory()->create();
        $otherConversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'team_id' => $this->team->id, // Same team but different user's conversation
        ]);

        $response = $this->get("/agent-teams/{$this->team->id}/stream/{$otherConversation->id}");

        $response->assertStatus(403);
    });
});
