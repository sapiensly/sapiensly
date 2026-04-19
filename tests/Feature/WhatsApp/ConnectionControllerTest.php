<?php

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Spatie\Permission\Models\Permission;

function actingWhatsAppUser(): User
{
    $user = User::factory()->create();
    Permission::firstOrCreate(['name' => 'whatsapp-connections.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'whatsapp-connections.create', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'whatsapp-connections.update', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'whatsapp-connections.delete', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'whatsapp-connections.reply', 'guard_name' => 'web']);

    return $user;
}

test('index lists whatsapp channels for the current user', function () {
    $user = actingWhatsAppUser();
    $channel = Channel::factory()->whatsapp()->active()->forUser($user)->create();
    WhatsAppConnection::factory()->forChannel($channel)->create();

    $this->actingAs($user)
        ->get(route('whatsapp.connections.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/whatsapp/Index')
            ->has('channels.data', 1));
});

test('index hides channels from other accounts', function () {
    $me = actingWhatsAppUser();
    $them = User::factory()->create();
    $theirChannel = Channel::factory()->whatsapp()->active()->forUser($them)->create();
    WhatsAppConnection::factory()->forChannel($theirChannel)->create();

    $this->actingAs($me)
        ->get(route('whatsapp.connections.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/whatsapp/Index')
            ->has('channels.data', 0));
});

test('store creates a channel + connection and redirects to show', function () {
    $user = actingWhatsAppUser();
    $agent = Agent::factory()->standalone()->active()->forUser($user)->create();

    $response = $this->actingAs($user)
        ->post(route('whatsapp.connections.store'), [
            'name' => 'My WA Business',
            'display_phone_number' => '+15551234567',
            'phone_number_id' => '19991234567890',
            'business_account_id' => '9998887776',
            'agent_id' => $agent->id,
            'auth' => [
                'access_token' => 'EAAtokenbuffer',
                'app_id' => '1234',
                'app_secret' => 'sekret-hash',
                'graph_api_version' => 'v20.0',
            ],
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('whatsapp_connections', [
        'phone_number_id' => '19991234567890',
        'business_account_id' => '9998887776',
    ]);

    $this->assertDatabaseHas('channels', [
        'name' => 'My WA Business',
        'channel_type' => ChannelType::WhatsApp->value,
        'agent_id' => $agent->id,
        'status' => ChannelStatus::Draft->value,
    ]);
});

test('store rejects a duplicate phone_number_id', function () {
    $user = actingWhatsAppUser();
    $agent = Agent::factory()->standalone()->active()->forUser($user)->create();
    $existing = Channel::factory()->whatsapp()->forUser($user)->create();
    WhatsAppConnection::factory()->forChannel($existing)->create(['phone_number_id' => '11111111111']);

    $this->actingAs($user)
        ->post(route('whatsapp.connections.store'), [
            'name' => 'Dup',
            'display_phone_number' => '+15551234567',
            'phone_number_id' => '11111111111',
            'business_account_id' => '9998887776',
            'agent_id' => $agent->id,
            'auth' => [
                'access_token' => 'EAA',
                'app_id' => '1234',
                'app_secret' => 's',
            ],
        ])
        ->assertSessionHasErrors(['phone_number_id']);
});

test('show returns the connection with webhook url and masked credentials', function () {
    $user = actingWhatsAppUser();
    $channel = Channel::factory()->whatsapp()->active()->forUser($user)->create();
    $conn = WhatsAppConnection::factory()->forChannel($channel)->create();

    $this->actingAs($user)
        ->get(route('whatsapp.connections.show', $conn))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('system/whatsapp/Show')
            ->has('connection.masked_auth.access_token_masked')
            ->has('webhook_url')
            ->has('verify_token'));
});

test('show returns 403 for connections owned by another user', function () {
    $me = actingWhatsAppUser();
    $them = User::factory()->create();
    $channel = Channel::factory()->whatsapp()->forUser($them)->create();
    $conn = WhatsAppConnection::factory()->forChannel($channel)->create();

    $this->actingAs($me)
        ->get(route('whatsapp.connections.show', $conn))
        ->assertForbidden();
});

test('update keeps existing credentials when auth fields are blank', function () {
    $user = actingWhatsAppUser();
    $channel = Channel::factory()->whatsapp()->forUser($user)->create();
    $conn = WhatsAppConnection::factory()->forChannel($channel)->create([
        'auth_config' => [
            'phone_number_id' => '999',
            'access_token' => 'KEEPME',
            'app_id' => '1',
            'app_secret' => 'OLDSECRET',
            'graph_api_version' => 'v20.0',
        ],
    ]);

    $this->actingAs($user)
        ->put(route('whatsapp.connections.update', $conn), [
            'name' => 'Renamed',
            'status' => ChannelStatus::Active->value,
            'auth' => [
                'access_token' => '',
                'app_secret' => 'NEWSECRET',
            ],
        ])
        ->assertRedirect();

    $fresh = $conn->fresh();
    expect($fresh->auth_config['access_token'])->toBe('KEEPME')
        ->and($fresh->auth_config['app_secret'])->toBe('NEWSECRET');

    expect($channel->fresh()->status)->toBe(ChannelStatus::Active);
});

test('destroy removes the connection and its channel', function () {
    $user = actingWhatsAppUser();
    $channel = Channel::factory()->whatsapp()->forUser($user)->create();
    $conn = WhatsAppConnection::factory()->forChannel($channel)->create();

    $this->actingAs($user)
        ->delete(route('whatsapp.connections.destroy', $conn))
        ->assertRedirect();

    $this->assertDatabaseMissing('whatsapp_connections', ['id' => $conn->id]);
    // Channel is soft-deleted, so deleted_at is set but row still present
    expect($channel->fresh()->deleted_at)->not->toBeNull();
});
