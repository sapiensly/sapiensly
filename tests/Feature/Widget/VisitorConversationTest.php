<?php

use App\Ai\RuntimeAgent;
use App\Enums\BotFlowStatus;
use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Agent;
use App\Models\BotFlow;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetMessage;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;

/**
 * The journey no test made before this one: a visitor opens the widget, says
 * something, and the bot ANSWERS.
 *
 * Every other widget test asserts the plumbing around a conversation — that a
 * session is created, that a message is stored, that the counter goes up. None
 * of them ever asked the bot to reply, which is why three separate bugs lived
 * in the answer path at once (no provider credentials, only the first turn ever
 * answered, and content frames the client silently dropped). Each one alone
 * left the widget mute, and the suite stayed green through all three.
 *
 * So this file drives the journey end to end, over HTTP, in the same order the
 * bundle does it: session → conversation → message → stream → reply.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);

    $this->agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'prompt_template' => 'Eres el concierge.',
    ]);
    $this->chatbot = Chatbot::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'name' => 'Concierge',
        'status' => ChatbotStatus::Active,
        'config' => [],
    ]);
    $this->token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'Default Token',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat', 'feedback'],
    ]);
    BotFlow::create([
        'chatbot_id' => $this->chatbot->id,
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'name' => 'Roster',
        'status' => BotFlowStatus::Active,
        'definition' => [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger' => 'conversation_start']],
                // roster() recognizes triage | knowledge | action only.
                ['id' => 'a1', 'type' => 'agent', 'position' => ['x' => 200, 'y' => 0], 'data' => [
                    'role' => 'knowledge', 'agent_id' => $this->agent->id, 'agent_name' => $this->agent->name,
                ]],
            ],
            'edges' => [],
        ],
    ]);
});

/** Open a widget session the way the bundle does, returning its token. */
function visitorSession(string $apiToken): string
{
    return test()->postJson('/api/widget/v1/sessions', [], [
        'Authorization' => "Bearer {$apiToken}",
    ])->assertCreated()->json('session_token');
}

/** Start a conversation on that session. */
function visitorConversation(string $apiToken, string $sessionToken): string
{
    return test()->postJson('/api/widget/v1/conversations', [
        'session_token' => $sessionToken,
    ], ['Authorization' => "Bearer {$apiToken}"])->assertCreated()->json('conversation_id');
}

/**
 * Say something and read the reply off the stream, exactly as the bundle does:
 * POST the message, then consume the SSE the response points at.
 *
 * @return array{frames: list<array<string, mixed>>, text: string}
 */
function visitorSays(string $apiToken, string $sessionToken, string $conversationId, string $text): array
{
    // The session token rides along on every call, exactly as the bundle sends
    // it: the API token is public, so it is the session that says which visitor
    // this is and which conversations are theirs.
    $headers = ['Authorization' => "Bearer {$apiToken}", 'X-Session-Token' => $sessionToken];

    test()->postJson("/api/widget/v1/conversations/{$conversationId}/messages", [
        'content' => $text,
    ], $headers)->assertCreated();

    $body = test()->get("/api/widget/v1/conversations/{$conversationId}/stream", $headers)
        ->assertOk()->streamedContent();

    $frames = [];
    foreach (explode("\n", $body) as $line) {
        if (! str_starts_with($line, 'data: ')) {
            continue;
        }
        $payload = substr($line, 6);
        if ($payload === '[DONE]') {
            continue;
        }
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $frames[] = $decoded;
        }
    }

    // What the visitor actually reads: only frames the client dispatches on.
    $text = '';
    foreach ($frames as $frame) {
        if (($frame['type'] ?? null) === 'content') {
            $text .= $frame['content'] ?? '';
        }
    }

    return ['frames' => $frames, 'text' => $text];
}

it('answers a visitor, and the visitor can actually read the answer', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['El plan arranca en $349 MXN al mes.']);

    $session = visitorSession($this->token->token);
    $conversation = visitorConversation($this->token->token, $session);
    $turn = visitorSays($this->token->token, $session, $conversation, '¿Cuánto cuesta?');

    // Reaching the browser is the assertion: a reply that is persisted but never
    // dispatched by the client is exactly the bug this file exists to catch.
    expect($turn['text'])->toContain('$349');

    // …and it is on the record, so a reload shows the same conversation.
    expect(WidgetMessage::where('role', MessageRole::Assistant)->value('content'))
        ->toContain('$349');
});

it('keeps answering after the first turn', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['Cuesta $349.', 'Llega cada mes.', 'Sí, puedes pausarlo.']);

    $session = visitorSession($this->token->token);
    $conversation = visitorConversation($this->token->token, $session);

    $first = visitorSays($this->token->token, $session, $conversation, '¿Cuánto cuesta?');
    $second = visitorSays($this->token->token, $session, $conversation, '¿Cada cuándo llega?');
    $third = visitorSays($this->token->token, $session, $conversation, '¿Puedo pausar?');

    expect($first['text'])->not->toBe('')
        ->and($second['text'])->not->toBe('')
        ->and($third['text'])->not->toBe('');

    expect(WidgetMessage::where('role', MessageRole::Assistant)->count())->toBe(3);
});

it('sends every content frame typed, because the client dispatches on type', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['Hola.']);

    $session = visitorSession($this->token->token);
    $conversation = visitorConversation($this->token->token, $session);
    $turn = visitorSays($this->token->token, $session, $conversation, 'Hola');

    $carryingContent = array_filter($turn['frames'], fn ($f) => array_key_exists('content', $f));

    expect($carryingContent)->not->toBeEmpty();
    foreach ($carryingContent as $frame) {
        expect($frame['type'] ?? null)->toBe('content');
    }
});

it('needs credentials of its own: an anonymous turn resolves the owner provider config', function () {
    Ai::fakeAgent(RuntimeAgent::class, ['Listo.']);

    $session = visitorSession($this->token->token);
    $conversation = visitorConversation($this->token->token, $session);
    $turn = visitorSays($this->token->token, $session, $conversation, 'Hola');

    // A widget request carries no session user, so nothing in the `api` group
    // applies the tenant's provider keys — the turn has to bind them from the
    // chatbot's owner or every provider call dies on auth.
    foreach ($turn['frames'] as $frame) {
        expect($frame)->not->toHaveKey('error');
    }
    expect($turn['text'])->toBe('Listo.');
});
