<?php

use App\Enums\ChatbotStatus;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;
use App\Services\ChatbotAnalyticsService;
use Illuminate\Support\Str;

/**
 * The one answer that makes the resolution rate mean anything.
 *
 * `resolution_rate` is computed from `is_resolved`, `is_resolved` is only ever
 * written by the feedback endpoint, and nothing in the widget ever called it —
 * the endpoint existed, the client method existed, and no UI reached either. So
 * the metric read 0 forever no matter how well a bot was doing, and it is the
 * metric the whole product would be judged on.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->chatbot = Chatbot::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'name' => 'Concierge',
        'status' => ChatbotStatus::Active,
        'config' => [],
    ]);
    $this->token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'T',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat', 'feedback'],
    ]);
    $this->session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => Str::random(64),
    ]);
    $this->conversation = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'widget_session_id' => $this->session->id,
    ]);
});

function visitorHeaders(string $apiToken, string $sessionToken): array
{
    return ['Authorization' => "Bearer {$apiToken}", 'X-Session-Token' => $sessionToken];
}

it('records a resolved conversation when the visitor says it helped', function () {
    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/feedback",
        ['rating' => 5, 'is_resolved' => true],
        visitorHeaders($this->token->token, $this->session->session_token),
    )->assertOk()->assertJsonPath('is_resolved', true);

    expect($this->conversation->fresh()->is_resolved)->toBeTrue();
});

it('records an unresolved one just as clearly', function () {
    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/feedback",
        ['rating' => 1, 'is_resolved' => false],
        visitorHeaders($this->token->token, $this->session->session_token),
    )->assertOk();

    expect($this->conversation->fresh()->is_resolved)->toBeFalse()
        ->and($this->conversation->fresh()->rating)->toBe(1);
});

it('turns those answers into a resolution rate the owner can read', function () {
    $resolved = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'widget_session_id' => $this->session->id,
        'is_resolved' => true,
    ]);
    WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'widget_session_id' => $this->session->id,
        'is_resolved' => false,
    ]);

    $metrics = app(ChatbotAnalyticsService::class)->getOverview(
        $this->chatbot,
        now()->subDay(),
        now()->addDay(),
    );

    // Three conversations exist, one of them resolved.
    expect($metrics['resolution_rate'])->toBeGreaterThan(0)
        ->and($resolved->fresh()->is_resolved)->toBeTrue();
});

/**
 * Feedback was the last endpoint still resolving a conversation by chatbot alone
 * — a stranger holding the public token could rate, and mark resolved, someone
 * else's conversation. The first pass at the ownership fix patched chat and left
 * this one behind.
 */
it('will not let a stranger rate a conversation that is not theirs', function () {
    $other = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => Str::random(64),
    ]);

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/feedback",
        ['rating' => 1, 'is_resolved' => false],
        visitorHeaders($this->token->token, $other->session_token),
    )->assertNotFound();

    expect($this->conversation->fresh()->rating)->toBeNull();
});
