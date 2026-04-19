<?php

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\ChatbotStatus;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\Visibility;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WidgetConversation;
use App\Models\WidgetSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;

test('Channel model persists with enum casts', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $channel = Channel::factory()->forUser($user)->whatsapp()->active()->create(['name' => 'My WA']);

    expect($channel->fresh()->channel_type)->toBe(ChannelType::WhatsApp)
        ->and($channel->fresh()->status)->toBe(ChannelStatus::Active);
});

test('Channel id uses the chan_ prefix', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $channel = Channel::factory()->forUser($user)->create();
    expect($channel->id)->toStartWith('chan_');
});

test('Contact belongs to a Channel and defaults to active (not opted-out)', function () {
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->forChannel($channel)->create();

    expect($contact->channel->id)->toBe($channel->id)
        ->and($contact->isOptedOut())->toBeFalse()
        ->and($contact->id)->toStartWith('cont_');
});

test('Contact session window closes 24h after the last inbound', function () {
    $contact = Contact::factory()->recentlyActive()->create();
    expect($contact->isWithinSessionWindow())->toBeTrue();

    $contact->update(['last_inbound_at' => now()->subDays(2)]);
    expect($contact->fresh()->isWithinSessionWindow())->toBeFalse();
});

test('Contact identifier is unique per Channel', function () {
    $channel = Channel::factory()->create();
    Contact::factory()->forChannel($channel)->create(['identifier' => 'wa_12345']);

    expect(fn () => Contact::factory()->forChannel($channel)->create(['identifier' => 'wa_12345']))
        ->toThrow(QueryException::class);
});

test('Creating a chatbot provisions its companion Channel', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => MembershipRole::Owner,
        'status' => MembershipStatus::Active,
    ]);
    setPermissionsTeamId($org->id);

    $agent = Agent::factory()->forUser($user)->create();

    $this->actingAs($user)->post('/chatbots', [
        'name' => 'Bot',
        'agent_id' => $agent->id,
    ]);

    $chatbot = Chatbot::where('name', 'Bot')->first();
    expect($chatbot)->not->toBeNull()
        ->and($chatbot->channel_id)->not->toBeNull();

    $channel = Channel::find($chatbot->channel_id);
    expect($channel)->not->toBeNull()
        ->and($channel->channel_type)->toBe(ChannelType::Widget)
        ->and($channel->agent_id)->toBe($agent->id)
        ->and($channel->status)->toBe(ChannelStatus::Draft);
});

test('Creating a widget session materialises a Contact in the widgets Channel', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $channel = Channel::factory()->widget()->forUser($user)->active()->create();
    $chatbot = Chatbot::factory()->create([
        'user_id' => $user->id,
        'organization_id' => null,
        'visibility' => Visibility::Private,
        'channel_id' => $channel->id,
        'status' => ChatbotStatus::Active,
    ]);

    $token = ChatbotApiToken::create([
        'chatbot_id' => $chatbot->id,
        'name' => 'test',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat'],
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$token->token,
        'Origin' => 'https://example.test',
    ])->postJson('/api/widget/v1/sessions', [
        'visitor_email' => 'visitor@example.com',
        'visitor_name' => 'Alice',
    ]);

    $response->assertStatus(201);
    $session = WidgetSession::where('chatbot_id', $chatbot->id)->first();
    expect($session->contact_id)->not->toBeNull();

    $contact = Contact::find($session->contact_id);
    expect($contact->channel_id)->toBe($channel->id)
        ->and($contact->email)->toBe('visitor@example.com')
        ->and($contact->profile_name)->toBe('Alice')
        ->and($contact->identifier)->toBe($session->session_token);
});

test('Creating a conversation propagates channel_id and contact_id', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $channel = Channel::factory()->widget()->forUser($user)->active()->create();
    $chatbot = Chatbot::factory()->create([
        'user_id' => $user->id,
        'organization_id' => null,
        'visibility' => Visibility::Private,
        'channel_id' => $channel->id,
        'status' => ChatbotStatus::Active,
    ]);

    $contact = Contact::factory()->forChannel($channel)->create();
    $session = WidgetSession::create([
        'chatbot_id' => $chatbot->id,
        'contact_id' => $contact->id,
        'session_token' => $contact->identifier,
        'last_activity_at' => now(),
    ]);

    $token = ChatbotApiToken::create([
        'chatbot_id' => $chatbot->id,
        'name' => 'test',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat'],
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$token->token,
        'Origin' => 'https://example.test',
    ])->postJson('/api/widget/v1/conversations', [
        'session_token' => $session->session_token,
    ])->assertStatus(201);

    $conv = WidgetConversation::first();
    expect($conv->channel_id)->toBe($channel->id)
        ->and($conv->contact_id)->toBe($contact->id);
});
