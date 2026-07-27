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
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;

/**
 * Three bugs, all found by putting a real chatbot on a real landing and typing
 * into it. Each one made the widget look mute in a different way.
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
        'name' => 'Default Token',
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

function widgetMessage(WidgetConversation $conversation, MessageRole $role, string $content, string $at): WidgetMessage
{
    $message = WidgetMessage::create([
        'widget_conversation_id' => $conversation->id,
        'role' => $role,
        'content' => $content,
    ]);
    $message->forceFill(['created_at' => $at])->save();

    return $message;
}

/**
 * The messages() relation bakes in an ASCENDING order for the transcript, and
 * the stream guard chained ->latest() onto it — which only appends, so ascending
 * still won and "the last user message" resolved to the FIRST one. Every turn
 * after the opening one then looked already-answered and got a 400, so the bot
 * replied once per conversation and went silent forever after.
 */
it('answers the newest turn, not the first one, once a conversation has history', function () {
    widgetMessage($this->conversation, MessageRole::User, '¿Cuánto cuesta?', '2026-07-27 10:00:00');
    widgetMessage($this->conversation, MessageRole::Assistant, 'Cuesta $349.', '2026-07-27 10:00:05');
    widgetMessage($this->conversation, MessageRole::User, '¿Y los envíos?', '2026-07-27 10:01:00');

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$this->token->token, 'X-Session-Token' => $this->session->session_token])
        ->get('/api/widget/v1/conversations/'.$this->conversation->id.'/stream');

    // It must get PAST the guard. What it then streams depends on the roster,
    // which this test does not configure — the point is that it is not refused.
    $response->assertOk();
    expect($response->streamedContent())->not->toContain('Already responded');
});

it('still refuses a turn that genuinely has its answer', function () {
    widgetMessage($this->conversation, MessageRole::User, '¿Cuánto cuesta?', '2026-07-27 10:00:00');
    widgetMessage($this->conversation, MessageRole::Assistant, 'Cuesta $349.', '2026-07-27 10:00:05');

    $this->withHeaders(['Authorization' => 'Bearer '.$this->token->token, 'X-Session-Token' => $this->session->session_token])
        ->get('/api/widget/v1/conversations/'.$this->conversation->id.'/stream')
        ->assertStatus(400)
        ->assertJsonPath('error', 'Already responded');
});

/**
 * The widget dispatches on `event.type`. Content chunks went out untyped, so the
 * client parsed them, matched nothing and dropped them — while the server still
 * persisted the reply. The bot therefore "answered" only after a page reload.
 */
it('tags streamed content chunks so the client can dispatch on them', function () {
    $agent = Agent::factory()->standalone()->general()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'prompt_template' => 'Eres el concierge.',
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
                // roster() only recognizes triage | knowledge | action.
                ['id' => 'a1', 'type' => 'agent', 'position' => ['x' => 200, 'y' => 0], 'data' => [
                    'role' => 'knowledge', 'agent_id' => $agent->id, 'agent_name' => $agent->name,
                ]],
            ],
            'edges' => [],
        ],
    ]);
    Ai::fakeAgent(RuntimeAgent::class, ['Cuesta $349 al mes.']);

    widgetMessage($this->conversation, MessageRole::User, 'Hola', '2026-07-27 10:00:00');

    $body = $this->withHeaders(['Authorization' => 'Bearer '.$this->token->token, 'X-Session-Token' => $this->session->session_token])
        ->get('/api/widget/v1/conversations/'.$this->conversation->id.'/stream')
        ->streamedContent();

    $contentFrames = [];
    foreach (explode("\n", $body) as $line) {
        if (! str_starts_with($line, 'data: ')) {
            continue;
        }
        $payload = json_decode(substr($line, 6), true);
        if (is_array($payload) && array_key_exists('content', $payload)) {
            $contentFrames[] = $payload;
        }
    }

    // Every frame carrying content must name its type, or the widget drops it.
    expect($contentFrames)->not->toBeEmpty();
    foreach ($contentFrames as $frame) {
        expect($frame['type'] ?? null)->toBe('content');
    }
});
