<?php

use App\Enums\ChatbotStatus;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetSession;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * A rate limit keyed on something the caller picks is not a rate limit.
 *
 * The widget bucket used to include the session token, which whoever is calling
 * chooses: mint a new session (cheap) and you mint a fresh allowance with it. So
 * a single address could rotate identities and spin the meter — every stream a
 * model call billed to the tenant, with only the organization's spend budget
 * underneath, and a budget bounds the total, not the rate.
 */
beforeEach(function () {
    RateLimiter::clear('');
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
        'abilities' => ['chat'],
    ]);
});

/** A fresh session — the thing an attacker rotates to reset their allowance. */
function freshSessionToken(Chatbot $chatbot): string
{
    $session = WidgetSession::create([
        'chatbot_id' => $chatbot->id,
        'session_token' => Str::random(64),
    ]);

    return $session->session_token;
}

it('stops one address from buying more allowance by inventing sessions', function () {
    $statuses = [];

    // Forty attempts, each from a BRAND NEW session. Under the old key every one
    // of these looked like a different visitor with a clean slate.
    for ($i = 0; $i < 45; $i++) {
        $statuses[] = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token->token,
            'X-Session-Token' => freshSessionToken($this->chatbot),
        ])->get('/api/widget/v1/conversations/does-not-exist/stream')->status();
    }

    // The per-address ceiling has to bite regardless of how many identities were
    // used. Without it, all forty-five would have sailed through.
    expect($statuses)->toContain(429);
});

it('does not punish a shared address for being shared', function () {
    // An office behind one NAT address: several real visitors, a handful of
    // turns each. This must stay well clear of the ceiling.
    $statuses = [];
    foreach (range(1, 5) as $visitor) {
        $session = freshSessionToken($this->chatbot);
        foreach (range(1, 4) as $turn) {
            $statuses[] = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->token->token,
                'X-Session-Token' => $session,
            ])->get('/api/widget/v1/conversations/does-not-exist/stream')->status();
        }
    }

    expect($statuses)->not->toContain(429);
});

it('still limits a single runaway session', function () {
    $session = freshSessionToken($this->chatbot);

    $statuses = [];
    for ($i = 0; $i < 15; $i++) {
        $statuses[] = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token->token,
            'X-Session-Token' => $session,
        ])->get('/api/widget/v1/conversations/does-not-exist/stream')->status();
    }

    // One tab hammering the bot is the fairness case the per-visitor bucket owns.
    expect($statuses)->toContain(429);
});
