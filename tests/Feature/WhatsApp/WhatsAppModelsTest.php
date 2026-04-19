<?php

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('WhatsAppConnection encrypts auth_config at rest', function () {
    $connection = WhatsAppConnection::factory()->create();

    $raw = DB::table('whatsapp_connections')->where('id', $connection->id)->value('auth_config');
    expect($raw)->not->toContain('access_token')
        ->and($connection->fresh()->auth_config['access_token'])->toStartWith('EAA');
});

test('WhatsAppConnection uses the wac_ prefix', function () {
    expect(WhatsAppConnection::factory()->create()->id)->toStartWith('wac_');
});

test('WhatsAppConnection is tied 1:1 to a whatsapp-typed Channel', function () {
    $connection = WhatsAppConnection::factory()->create();

    expect($connection->channel->channel_type)->toBe(ChannelType::WhatsApp)
        ->and($connection->channel->whatsAppConnection->id)->toBe($connection->id);
});

test('phone_number_id is globally unique', function () {
    $first = WhatsAppConnection::factory()->create(['phone_number_id' => '1234567890']);

    expect(fn () => WhatsAppConnection::factory()->create(['phone_number_id' => '1234567890']))
        ->toThrow(QueryException::class);
});

test('maskedAuthConfig redacts tokens', function () {
    $connection = WhatsAppConnection::factory()->create();
    $masked = $connection->maskedAuthConfig();

    expect($masked['access_token_masked'])->toContain('…')
        ->and($masked['access_token_masked'])->not->toContain($connection->auth_config['access_token']);
});

test('WhatsAppConversation uses a ULID and Contact relations', function () {
    $channel = Channel::factory()->whatsapp()->active()->create();
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15555551234')->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create();

    expect($conv->id)->toHaveLength(26) // ULID
        ->and($conv->status)->toBe(ConversationStatus::Open)
        ->and($conv->contact->id)->toBe($contact->id)
        ->and($conv->channel->id)->toBe($channel->id);
});

test('WhatsAppMessage wamid is globally unique', function () {
    $conv = WhatsAppConversation::factory()->create();
    WhatsAppMessage::factory()->forConversation($conv)->create(['wamid' => 'wamid.ABCDEF']);

    expect(fn () => WhatsAppMessage::factory()->forConversation($conv)->create(['wamid' => 'wamid.ABCDEF']))
        ->toThrow(QueryException::class);
});

test('WhatsAppMessage advanceStatusTo is monotonic', function () {
    $message = WhatsAppMessage::factory()->create(['status' => MessageStatus::Sent]);

    expect($message->advanceStatusTo(MessageStatus::Delivered))->toBeTrue();
    expect($message->fresh()->status)->toBe(MessageStatus::Delivered);

    // Downgrade attempt: sent < delivered, must be refused.
    expect($message->fresh()->advanceStatusTo(MessageStatus::Sent))->toBeFalse();
    expect($message->fresh()->status)->toBe(MessageStatus::Delivered);

    // Progress to read is fine.
    expect($message->fresh()->advanceStatusTo(MessageStatus::Read))->toBeTrue();
    expect($message->fresh()->status)->toBe(MessageStatus::Read);
});

test('WhatsAppMessage advanceStatusTo records status history', function () {
    $message = WhatsAppMessage::factory()->create(['status' => MessageStatus::Sent]);
    $message->advanceStatusTo(MessageStatus::Delivered, ['source' => 'webhook']);
    $message->advanceStatusTo(MessageStatus::Read);

    $history = $message->fresh()->status_updates;
    expect($history)->toHaveCount(2)
        ->and($history[0])->toMatchArray(['from' => 'sent', 'to' => 'delivered', 'source' => 'webhook'])
        ->and($history[1])->toMatchArray(['from' => 'delivered', 'to' => 'read']);
});

test('Conversation auto-reply is suppressed when status is escalated', function () {
    $conv = WhatsAppConversation::factory()->escalated()->create();
    expect($conv->isAutoReplyEnabled())->toBeFalse();

    $open = WhatsAppConversation::factory()->create(['status' => ConversationStatus::Open]);
    expect($open->isAutoReplyEnabled())->toBeTrue();
});

test('WhatsAppTemplate stores components as structured JSON', function () {
    $conn = WhatsAppConnection::factory()->create();
    $template = WhatsAppTemplate::factory()->create([
        'whatsapp_connection_id' => $conn->id,
        'components' => [
            ['type' => 'header', 'format' => 'text', 'text' => 'Hi {{1}}'],
            ['type' => 'body', 'text' => 'Your order {{1}} is ready.'],
        ],
    ]);

    expect($template->components)->toHaveCount(2)
        ->and($template->components[0]['type'])->toBe('header');
});

test('WhatsAppMessage content_type casts to enum', function () {
    $message = WhatsAppMessage::factory()->create([
        'content_type' => WhatsAppContentType::Image,
        'media_url' => 'https://example.test/image.jpg',
    ]);

    expect($message->content_type)->toBe(WhatsAppContentType::Image)
        ->and($message->content_type->isMedia())->toBeTrue()
        ->and($message->direction)->toBe(MessageDirection::Inbound);
});
