<?php

use App\Enums\ChatbotStatus;
use App\Enums\ConversationStatus;
use App\Enums\HandoffMode;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\MessageRole;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;
use App\Services\Chatbots\ConversationTakeover;
use App\Services\Chatbots\HandoffResolver;
use App\Services\Chatbots\OperatorPresence;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/**
 * Phase 2: a person actually takes the conversation, and their words reach the
 * visitor.
 *
 * Phase 1 stopped deliberately short of this. It recorded escalations and
 * refused to mute the bot, because muting it while nobody could answer would
 * have left the visitor talking to a wall — the offer was bounded by what the
 * product could honour. These tests are that boundary moving.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->operator = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id, 'name' => 'Sofía']);

    // An org member's authority comes from their membership role, so the test
    // has to give them one — a bare user in an organization can do nothing,
    // which is the correct default and not what an operator is.
    OrganizationMembership::create([
        'organization_id' => $this->org->id,
        'user_id' => $this->operator->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);
    setPermissionsTeamId($this->org->id);

    $this->channel = Channel::create([
        'user_id' => $this->operator->id,
        'organization_id' => $this->org->id,
        'channel_type' => 'widget',
        'name' => 'Web',
    ]);
    $this->chatbot = Chatbot::create([
        'user_id' => $this->operator->id,
        'organization_id' => $this->org->id,
        'channel_id' => $this->channel->id,
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
    $this->session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => Str::random(64),
        'last_activity_at' => now(),
    ]);
    $this->conversation = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'channel_id' => $this->channel->id,
        'widget_session_id' => $this->session->id,
    ]);
    $this->visitorHeaders = [
        'Authorization' => "Bearer {$this->token->token}",
        'X-Session-Token' => $this->session->session_token,
    ];
});

it('only promises a person while someone is actually watching', function () {
    $resolver = app(HandoffResolver::class);

    expect($resolver->forOwner($this->org->id)->mode)->toBe(HandoffMode::Capture);

    app(OperatorPresence::class)->touch($this->operator);

    // A fresh resolver: presence must not be answered from a memo, because it is
    // the one input that changes minute to minute.
    expect(app(HandoffResolver::class)->forOwner($this->org->id)->mode)->toBe(HandoffMode::Live);
});

it('stops promising a person the moment they close the inbox', function () {
    $presence = app(OperatorPresence::class);
    $presence->touch($this->operator);
    $presence->forget($this->operator);

    expect(app(HandoffResolver::class)->forOwner($this->org->id)->mode)->toBe(HandoffMode::Capture);
});

/**
 * Every failure mode of "is someone there?" has to resolve to no. A false yes is
 * a promise broken in front of a customer.
 */
it('says nobody is there when it cannot tell', function () {
    expect(app(OperatorPresence::class)->anyoneWatching(null))->toBeFalse()
        ->and(app(OperatorPresence::class)->anyoneWatching('org_nonexistent'))->toBeFalse();
});

it('lets an operator take a conversation and mutes the bot', function () {
    $this->actingAs($this->operator)
        ->post(route('chatbots.conversation.takeover', [$this->chatbot, $this->conversation]))
        ->assertRedirect();

    $conversation = $this->conversation->fresh();

    expect($conversation->status)->toBe(ConversationStatus::Escalated)
        ->and($conversation->assigned_user_id)->toBe($this->operator->id)
        ->and($conversation->botMayReply())->toBeFalse();
});

/**
 * The seam matters. A conversation that goes quiet and then answers in a
 * different voice with no announcement is how a person gets mistaken for the bot.
 */
it('tells the visitor when a person joins', function () {
    $this->actingAs($this->operator)
        ->post(route('chatbots.conversation.takeover', [$this->chatbot, $this->conversation]));

    expect($this->conversation->fresh()->lastMessage()->content)
        ->toContain('joined');
});

it('delivers the human reply to the widget, marked as a person', function () {
    $this->actingAs($this->operator)
        ->post(route('chatbots.conversation.reply', [$this->chatbot, $this->conversation]), [
            'content' => 'Hola, soy Sofía del equipo.',
        ])->assertRedirect();

    $payload = $this->getJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/messages",
        $this->visitorHeaders,
    )->assertOk()->json();

    $human = collect($payload['messages'])->firstWhere('human', true);

    expect($human['content'])->toBe('Hola, soy Sofía del equipo.')
        ->and($human['sender_name'])->toBe('Sofía')
        ->and($payload['with_person'])->toBeTrue();
});

/**
 * The poll. Re-sending the whole transcript every few seconds would work and be
 * wasteful; `after` is what makes an open widget cheap.
 */
it('returns only what the visitor has not seen yet', function () {
    $first = $this->conversation->addMessage(MessageRole::User, 'hola');
    $this->conversation->addMessage(MessageRole::Assistant, 'buenas');

    $payload = $this->getJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/messages?after={$first->id}",
        $this->visitorHeaders,
    )->assertOk()->json();

    expect($payload['messages'])->toHaveCount(1)
        ->and($payload['messages'][0]['content'])->toBe('buenas');
});

it('replying takes the conversation, so two voices never answer at once', function () {
    $this->actingAs($this->operator)
        ->post(route('chatbots.conversation.reply', [$this->chatbot, $this->conversation]), [
            'content' => 'yo te ayudo',
        ]);

    expect($this->conversation->fresh()->status)->toBe(ConversationStatus::Escalated);
});

it('gives the conversation back when the operator releases it', function () {
    $takeover = app(ConversationTakeover::class);
    $takeover->take($this->conversation, $this->operator);

    $this->actingAs($this->operator)
        ->post(route('chatbots.conversation.release', [$this->chatbot, $this->conversation]));

    $conversation = $this->conversation->fresh();

    expect($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->assigned_user_id)->toBeNull()
        // The escalation is answered, so it leaves the "asked for a person" queue.
        ->and($conversation->metadata['handoff']['resolved_at'])->not->toBeNull();
});

/**
 * The failure mode the whole feature can produce by WORKING: taken over, then
 * abandoned. A visitor waiting on someone who closed their laptop is worse than
 * never having been offered a person at all.
 */
it('gives back a conversation whose operator went quiet', function () {
    $takeover = app(ConversationTakeover::class);
    $takeover->take($this->conversation, $this->operator);
    $takeover->reply($this->conversation, $this->operator, 'ahorita reviso');

    $this->travel(ConversationTakeover::UNATTENDED_MINUTES + 1)->minutes();

    expect($takeover->reclaimUnattended($this->chatbot))->toBe(1)
        ->and($this->conversation->fresh()->status)->toBe(ConversationStatus::Open)
        ->and($this->conversation->fresh()->lastMessage()->content)->not->toBe('');
});

it('does not interrupt an operator who is still replying', function () {
    $takeover = app(ConversationTakeover::class);
    $takeover->take($this->conversation, $this->operator);

    $this->travel(ConversationTakeover::UNATTENDED_MINUTES + 1)->minutes();

    // They just said something, so the silence clock starts over.
    $takeover->reply($this->conversation, $this->operator, 'sigo aquí');

    expect($takeover->reclaimUnattended($this->chatbot))->toBe(0)
        ->and($this->conversation->fresh()->status)->toBe(ConversationStatus::Escalated);
});

/**
 * A conversation grabbed and never answered is the same abandonment, and would
 * otherwise sit escalated forever because there is no operator message to
 * measure silence from.
 */
it('gives back a conversation taken and never spoken in', function () {
    app(ConversationTakeover::class)->take($this->conversation, $this->operator);

    $this->travel(ConversationTakeover::UNATTENDED_MINUTES + 1)->minutes();

    expect(app(ConversationTakeover::class)->reclaimUnattended($this->chatbot))->toBe(1);
});

it('refuses a takeover from someone outside the organization', function () {
    $stranger = User::factory()->create([
        'email_verified_at' => now(),
        'organization_id' => Organization::create(['name' => 'Other', 'slug' => 'other-'.Str::lower(Str::random(6))])->id,
    ]);

    $this->actingAs($stranger)
        ->post(route('chatbots.conversation.takeover', [$this->chatbot, $this->conversation]))
        ->assertForbidden();
});

/**
 * The nested route does not prove the pairing — a conversation id from another
 * bot would otherwise be actionable by anyone who can reach one of their own.
 */
it('refuses to act on a conversation belonging to another bot', function () {
    $otherBot = Chatbot::create([
        'user_id' => $this->operator->id,
        'organization_id' => $this->org->id,
        'channel_id' => $this->channel->id,
        'name' => 'Otro',
        'status' => ChatbotStatus::Active,
        'config' => [],
    ]);

    $this->actingAs($this->operator)
        ->post(route('chatbots.conversation.takeover', [$otherBot, $this->conversation]))
        ->assertNotFound();
});
