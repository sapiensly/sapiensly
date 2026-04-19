<?php

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;

function makeInboxStack(User $user): array
{
    Permission::firstOrCreate(['name' => 'whatsapp-connections.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'whatsapp-connections.reply', 'guard_name' => 'web']);

    $channel = Channel::factory()->whatsapp()->active()->forUser($user)->create();
    WhatsAppConnection::factory()->forChannel($channel)->create();
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15557776666')->recentlyActive()->create();
    $conversation = WhatsAppConversation::factory()->forChannel($channel, $contact)->create([
        'status' => ConversationStatus::Open,
        'last_inbound_at' => now(),
    ]);
    WhatsAppMessage::factory()->forConversation($conversation)->create();

    return [$channel, $contact, $conversation];
}

test('inbox lists the user conversations', function () {
    $user = User::factory()->create();
    makeInboxStack($user);

    $this->actingAs($user)
        ->get(route('whatsapp.conversations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('system/whatsapp/Inbox')->has('conversations.data', 1));
});

test('takeover escalates a conversation and assigns the user', function () {
    $user = User::factory()->create();
    [, , $conversation] = makeInboxStack($user);

    $this->actingAs($user)
        ->post(route('whatsapp.conversations.takeover', $conversation))
        ->assertRedirect();

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Escalated)
        ->and($conversation->fresh()->assigned_user_id)->toBe($user->id);
});

test('release restores the conversation to Open', function () {
    $user = User::factory()->create();
    [, , $conversation] = makeInboxStack($user);
    $conversation->update([
        'status' => ConversationStatus::Escalated,
        'assigned_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('whatsapp.conversations.release', $conversation))
        ->assertRedirect();

    expect($conversation->fresh()->status)->toBe(ConversationStatus::Open)
        ->and($conversation->fresh()->assigned_user_id)->toBeNull();
});

test('reply queues an outbound message from the operator', function () {
    Queue::fake();
    $user = User::factory()->create();
    [, , $conversation] = makeInboxStack($user);

    $this->actingAs($user)
        ->post(route('whatsapp.conversations.reply', $conversation), [
            'content' => 'Hola, soy un operador humano.',
        ])
        ->assertRedirect();

    $message = WhatsAppMessage::where('whatsapp_conversation_id', $conversation->id)
        ->where('direction', MessageDirection::Outbound)
        ->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('Hola, soy un operador humano.')
        ->and($message->sender_user_id)->toBe($user->id);

    Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
});

test('reply outside the 24h window surfaces an error', function () {
    Queue::fake();
    $user = User::factory()->create();
    [, $contact, $conversation] = makeInboxStack($user);
    $contact->update(['last_inbound_at' => now()->subDays(3)]);

    $this->actingAs($user)
        ->post(route('whatsapp.conversations.reply', $conversation->fresh()), [
            'content' => 'Hola',
        ])
        ->assertSessionHasErrors(['content']);

    Queue::assertNotPushed(SendWhatsAppMessageJob::class);
});

test('reply is forbidden for conversations on another tenant', function () {
    $me = User::factory()->create();
    $them = User::factory()->create();
    makeInboxStack($me);
    [, , $theirs] = makeInboxStack($them);

    $this->actingAs($me)
        ->post(route('whatsapp.conversations.reply', $theirs), [
            'content' => 'Intrusion',
        ])
        ->assertForbidden();
});
