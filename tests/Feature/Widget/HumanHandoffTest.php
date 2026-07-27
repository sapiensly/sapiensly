<?php

use App\Ai\Tools\Visitor\LeaveMessageForTeamTool;
use App\Enums\ChatbotStatus;
use App\Enums\ConversationStatus;
use App\Enums\HandoffMode;
use App\Jobs\DispatchConversationEscalatedWorkflows;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;
use App\Services\Chatbots\ConversationEscalator;
use App\Services\Chatbots\HandoffResolver;
use App\Support\Ai\PublicTurnContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * What happens when a visitor asks for a person.
 *
 * The `human_handoff` node has existed since the bot flow editor shipped: in the
 * palette, the validator, the executor, the orchestrator. On the widget it wrote
 * `metadata.human_handoff` and stopped — a key no code in the repository ever
 * read. The visitor was told help was coming, the bot kept answering, and nobody
 * on the team learned the conversation existed. These tests are the difference.
 */
beforeEach(function () {
    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->user = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    $this->channel = Channel::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'channel_type' => 'widget',
        'name' => 'Web',
    ]);
    $this->chatbot = Chatbot::create([
        'user_id' => $this->user->id,
        'organization_id' => $this->org->id,
        'channel_id' => $this->channel->id,
        'name' => 'Concierge',
        'status' => ChatbotStatus::Active,
        'config' => [],
    ]);
    $this->contact = Contact::create([
        'channel_id' => $this->channel->id,
        'identifier' => Str::random(16),
    ]);
    $this->session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'contact_id' => $this->contact->id,
        'session_token' => Str::random(64),
        'last_activity_at' => now(),
    ]);
    $this->conversation = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'channel_id' => $this->channel->id,
        'contact_id' => $this->contact->id,
        'widget_session_id' => $this->session->id,
    ]);
});

it('records the escalation where an operator can find it', function () {
    Queue::fake();

    app(ConversationEscalator::class)->escalate(
        $this->chatbot,
        $this->conversation,
        reason: 'pidió facturación',
    );

    $handoff = $this->conversation->fresh()->metadata['handoff'];

    expect($handoff['reason'])->toBe('pidió facturación')
        ->and($handoff['mode'])->toBe(HandoffMode::Capture->value)
        ->and($handoff['resolved_at'])->toBeNull();
});

/**
 * The bot has to keep working. Muting it while no human can reply would leave
 * the visitor talking to nothing — strictly worse than the bug being fixed. The
 * status moves when a person takes the conversation, which cannot happen yet.
 */
it('does not silence the bot, because nobody is there to take over', function () {
    Queue::fake();

    app(ConversationEscalator::class)->escalate($this->chatbot, $this->conversation);

    expect($this->conversation->fresh()->status)->toBe(ConversationStatus::Open)
        ->and($this->conversation->fresh()->botMayReply())->toBeTrue();
});

it('tells the team through the workflow engine', function () {
    Queue::fake();

    app(ConversationEscalator::class)->escalate($this->chatbot, $this->conversation, reason: 'urgente');

    Queue::assertPushed(
        DispatchConversationEscalatedWorkflows::class,
        fn ($job) => $job->channelId === $this->channel->id
            && $job->payload['conversation_id'] === $this->conversation->id
            && $job->payload['reason'] === 'urgente',
    );
});

it('stays quiet when the flow said not to notify', function () {
    Queue::fake();

    app(ConversationEscalator::class)->escalate($this->chatbot, $this->conversation, notify: false);

    Queue::assertNotPushed(DispatchConversationEscalatedWorkflows::class);
});

/**
 * The offer the bot is allowed to make is "leave your details and someone
 * follows up". A promise that ends with the address scrolling past in the
 * transcript is not a promise kept.
 */
it('keeps the email the visitor leaves after being asked for one', function () {
    Queue::fake();
    $escalator = app(ConversationEscalator::class);

    $escalator->escalate($this->chatbot, $this->conversation);
    $escalator->captureContactDetails($this->conversation->fresh(), 'claro, es ana@example.com gracias');

    expect($this->contact->fresh()->email)->toBe('ana@example.com')
        ->and($this->session->fresh()->visitor_email)->toBe('ana@example.com');
});

it('does not mine addresses out of conversations that never asked for a person', function () {
    app(ConversationEscalator::class)
        ->captureContactDetails($this->conversation, 'mi correo es ana@example.com');

    expect($this->contact->fresh()->email)->toBeNull();
});

it('does not overwrite an address already on file', function () {
    Queue::fake();
    $this->contact->update(['email' => 'first@example.com']);
    $escalator = app(ConversationEscalator::class);

    $escalator->escalate($this->chatbot, $this->conversation);
    $escalator->captureContactDetails($this->conversation->fresh(), 'mejor escríbeme a second@example.com');

    expect($this->contact->fresh()->email)->toBe('first@example.com');
});

it('lifts what the visitor already told the widget onto the contact', function () {
    Queue::fake();
    $this->session->update(['visitor_email' => 'lead@example.com', 'visitor_name' => 'Ana']);

    app(ConversationEscalator::class)->escalate($this->chatbot, $this->conversation);

    expect($this->contact->fresh()->email)->toBe('lead@example.com')
        ->and($this->contact->fresh()->profile_name)->toBe('Ana');
});

/**
 * Found in a browser, not in a test: the bot said "he registrado tu mensaje con
 * tu correo, alguien del equipo se pondrá en contacto" and the database held
 * nothing at all. Escalation was reachable only from a scripted `human_handoff`
 * node, and the common bot — one LLM agent, no scripted menu — has none. The
 * tool is what a model can actually reach.
 */
it('takes a message when the model calls the tool', function () {
    Queue::fake();

    app(PublicTurnContext::class)->runPublic(
        fn () => (new LeaveMessageForTeamTool)->handle(
            new Request(['reason' => 'quiere facturación', 'email' => 'ana@example.com', 'name' => 'Ana']),
        ),
        $this->chatbot,
        $this->conversation,
    );

    $conversation = $this->conversation->fresh();

    expect($conversation->metadata['handoff']['reason'])->toBe('quiere facturación')
        ->and($conversation->contact->email)->toBe('ana@example.com')
        ->and($conversation->session->visitor_email)->toBe('ana@example.com');
});

/**
 * A chatbot created without a companion Channel has no Contact to hang an
 * address on — a live bot on this install had exactly that, and requiring a
 * Contact meant the escalation quietly kept nothing. The session always exists.
 */
it('still keeps the address when the bot has no channel and no contact', function () {
    Queue::fake();
    $this->conversation->update(['contact_id' => null]);

    app(ConversationEscalator::class)->escalate(
        $this->chatbot,
        $this->conversation->fresh(),
        reason: 'sin canal',
        visitorEmail: 'huerfano@example.com',
    );

    expect($this->session->fresh()->visitor_email)->toBe('huerfano@example.com');
});

it('refuses to claim it took a message outside a widget turn', function () {
    $answer = (string) (new LeaveMessageForTeamTool)->handle(
        new Request(['reason' => 'algo']),
    );

    expect($answer)->toContain('Do not tell them their message was registered');
});

it('offers to take details when the organization named nowhere to send people', function () {
    $offer = app(HandoffResolver::class)->forOwner($this->org->id);

    expect($offer->mode)->toBe(HandoffMode::Capture)
        ->and($offer->promptClause())->toContain('Never say you are transferring them');
});

it('points at the channel the organization declared in its Contextbook', function () {
    OrganizationAiContext::create([
        'organization_id' => $this->org->id,
        'enabled' => true,
        'profile' => ['escalation' => 'soporte@acme.mx'],
    ])->recompile()->save();

    $offer = app(HandoffResolver::class)->forOwner($this->org->id);

    expect($offer->mode)->toBe(HandoffMode::Redirect)
        ->and($offer->channel)->toBe('soporte@acme.mx')
        ->and($offer->promptClause())->toContain('soporte@acme.mx');
});

/**
 * Phase 2's foundation, testable today: the moment a person holds the
 * conversation, the bot must not answer over them.
 */
it('refuses to stream a bot reply on a conversation a person has taken', function () {
    $token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'T',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat'],
    ]);
    $headers = ['Authorization' => "Bearer {$token->token}", 'X-Session-Token' => $this->session->session_token];

    $this->postJson("/api/widget/v1/conversations/{$this->conversation->id}/messages", ['content' => 'hola'], $headers)
        ->assertSuccessful();

    $this->conversation->update([
        'status' => ConversationStatus::Escalated,
        'assigned_user_id' => $this->user->id,
    ]);

    $this->get("/api/widget/v1/conversations/{$this->conversation->id}/stream", $headers)
        ->assertStatus(409);
});
