<?php

use App\Enums\ChatbotStatus;
use App\Models\Agent;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\User;

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

describe('config', function () {
    it('returns chatbot configuration for valid token', function () {
        $response = $this->getJson("/api/widget/v1/config/{$this->token->token}");

        $response->assertOk()
            ->assertJsonStructure([
                'chatbot_id',
                'name',
                'config' => [
                    'appearance',
                    'behavior',
                ],
            ]);
    });

    it('returns 404 for invalid token', function () {
        $response = $this->getJson('/api/widget/v1/config/invalid-token');

        $response->assertNotFound()
            ->assertJson([
                'error' => 'Invalid token',
            ]);
    });

    it('returns 401 for expired token', function () {
        $this->token->update(['expires_at' => now()->subDay()]);

        $response = $this->getJson("/api/widget/v1/config/{$this->token->token}");

        $response->assertUnauthorized()
            ->assertJson([
                'error' => 'Expired token',
            ]);
    });

    it('returns 403 for inactive chatbot', function () {
        $this->chatbot->update(['status' => ChatbotStatus::Inactive]);

        $response = $this->getJson("/api/widget/v1/config/{$this->token->token}");

        $response->assertForbidden()
            ->assertJson([
                'error' => 'Chatbot inactive',
            ]);
    });

    it('updates last used timestamp', function () {
        $this->assertNull($this->token->fresh()->last_used_at);

        $this->getJson("/api/widget/v1/config/{$this->token->token}");

        $this->assertNotNull($this->token->fresh()->last_used_at);
    });
});
