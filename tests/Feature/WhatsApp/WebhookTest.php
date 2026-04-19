<?php

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Jobs\DownloadWhatsAppMediaJob;
use App\Jobs\GenerateWhatsAppReplyJob;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppConversationResolver;
use App\Services\WhatsApp\WhatsAppWebhookParser;
use Illuminate\Support\Facades\Queue;

function makeWhatsAppConnection(string $appSecret = 'test-app-secret', string $verifyToken = 'verify-me'): WhatsAppConnection
{
    $channel = Channel::factory()->whatsapp()->active()->create();

    return WhatsAppConnection::factory()->forChannel($channel)->create([
        'webhook_verify_token' => $verifyToken,
        'auth_config' => [
            'phone_number_id' => '123456789',
            'whatsapp_business_account_id' => '987654321',
            'access_token' => 'EAAtest',
            'app_id' => '1111',
            'app_secret' => $appSecret,
            'webhook_verify_token' => $verifyToken,
            'graph_api_version' => 'v20.0',
        ],
    ]);
}

function inboundTextPayload(string $waId, string $text, string $wamid, string $profileName = 'Test User'): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'WABA_ID',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => '123456789'],
                    'contacts' => [[
                        'wa_id' => $waId,
                        'profile' => ['name' => $profileName],
                    ]],
                    'messages' => [[
                        'id' => $wamid,
                        'from' => $waId,
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

function signWhatsAppPayload(array $payload, string $secret): string
{
    return 'sha256='.hash_hmac('sha256', json_encode($payload), $secret);
}

test('GET verify handshake echoes the challenge on correct token', function () {
    $connection = makeWhatsAppConnection(verifyToken: 'secret-verify');

    $response = $this->get(route('webhooks.whatsapp.verify', [
        'connection' => $connection->id,
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'secret-verify',
        'hub_challenge' => '1234567',
    ]));

    $response->assertOk();
    expect($response->getContent())->toBe('1234567');
});

test('GET verify rejects mismatched token', function () {
    $connection = makeWhatsAppConnection(verifyToken: 'secret-verify');

    $response = $this->get(route('webhooks.whatsapp.verify', [
        'connection' => $connection->id,
        'hub_mode' => 'subscribe',
        'hub_verify_token' => 'wrong',
        'hub_challenge' => 'x',
    ]));

    $response->assertForbidden();
});

test('GET verify rejects non-subscribe mode', function () {
    $connection = makeWhatsAppConnection(verifyToken: 'ok');

    $response = $this->get(route('webhooks.whatsapp.verify', [
        'connection' => $connection->id,
        'hub_mode' => 'unsubscribe',
        'hub_verify_token' => 'ok',
        'hub_challenge' => 'x',
    ]));

    $response->assertForbidden();
});

test('POST webhook rejects request with missing signature header', function () {
    $connection = makeWhatsAppConnection();
    $payload = inboundTextPayload('15551112222', 'hi', 'wamid.aaa');

    $response = $this->postJson(
        route('webhooks.whatsapp.receive', $connection),
        $payload,
    );

    $response->assertStatus(401);
});

test('POST webhook rejects forged signature', function () {
    $connection = makeWhatsAppConnection(appSecret: 'real-secret');
    $payload = inboundTextPayload('15551112222', 'hi', 'wamid.aaa');

    $response = $this->postJson(
        route('webhooks.whatsapp.receive', $connection),
        $payload,
        ['X-Hub-Signature-256' => signWhatsAppPayload($payload, 'wrong-secret')],
    );

    $response->assertStatus(401);
});

test('POST webhook accepts valid signature and enqueues the processing job', function () {
    Queue::fake();

    $connection = makeWhatsAppConnection(appSecret: 'real-secret');
    $payload = inboundTextPayload('15551112222', 'hola', 'wamid.good');

    $response = $this->postJson(
        route('webhooks.whatsapp.receive', $connection),
        $payload,
        ['X-Hub-Signature-256' => signWhatsAppPayload($payload, 'real-secret')],
    );

    $response->assertOk();

    Queue::assertPushedOn('whatsapp-webhooks', ProcessWhatsAppWebhookJob::class);
    expect($connection->fresh()->last_webhook_received_at)->not->toBeNull();
});

test('ProcessWhatsAppWebhookJob persists contact, conversation, and inbound message', function () {
    Queue::fake();

    $connection = makeWhatsAppConnection();
    $payload = inboundTextPayload('15551112222', 'hola mundo', 'wamid.alpha', 'María');

    (new ProcessWhatsAppWebhookJob($connection->id, $payload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    $contact = Contact::where('channel_id', $connection->channel_id)->first();
    expect($contact)->not->toBeNull()
        ->and($contact->identifier)->toBe('15551112222')
        ->and($contact->profile_name)->toBe('María')
        ->and($contact->phone_e164)->toBe('+15551112222');

    $conversation = WhatsAppConversation::where('contact_id', $contact->id)->first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->status)->toBe(ConversationStatus::Open)
        ->and($conversation->message_count)->toBe(1);

    $message = WhatsAppMessage::where('wamid', 'wamid.alpha')->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('hola mundo')
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->content_type)->toBe(WhatsAppContentType::Text);

    Queue::assertPushed(GenerateWhatsAppReplyJob::class);
});

test('ProcessWhatsAppWebhookJob is idempotent on duplicate wamid', function () {
    Queue::fake();

    $connection = makeWhatsAppConnection();
    $payload = inboundTextPayload('15551112222', 'hola', 'wamid.dup');

    $process = fn () => (new ProcessWhatsAppWebhookJob($connection->id, $payload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    $process();
    $process();

    expect(WhatsAppMessage::where('wamid', 'wamid.dup')->count())->toBe(1);
    expect(Contact::where('channel_id', $connection->channel_id)->count())->toBe(1);
});

test('ProcessWhatsAppWebhookJob detects STOP opt-out keyword and skips auto-reply', function () {
    Queue::fake();

    $connection = makeWhatsAppConnection();
    $payload = inboundTextPayload('15551112222', 'STOP', 'wamid.stop');

    (new ProcessWhatsAppWebhookJob($connection->id, $payload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    $contact = Contact::where('channel_id', $connection->channel_id)->first();
    expect($contact->opted_out_at)->not->toBeNull();
    Queue::assertNotPushed(GenerateWhatsAppReplyJob::class);
});

test('ProcessWhatsAppWebhookJob skips auto-reply when conversation is escalated', function () {
    Queue::fake();

    $connection = makeWhatsAppConnection();
    $channel = $connection->channel;
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15551112222')->create();
    WhatsAppConversation::factory()->forChannel($channel, $contact)->escalated()->create();

    $payload = inboundTextPayload('15551112222', 'ayuda', 'wamid.esc');

    (new ProcessWhatsAppWebhookJob($connection->id, $payload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    Queue::assertNotPushed(GenerateWhatsAppReplyJob::class);
});

test('ProcessWhatsAppWebhookJob dispatches DownloadWhatsAppMediaJob for image messages', function () {
    Queue::fake();

    $connection = makeWhatsAppConnection();
    $payload = [
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'contacts' => [['wa_id' => '15551112222', 'profile' => ['name' => 'X']]],
                    'messages' => [[
                        'id' => 'wamid.image1',
                        'from' => '15551112222',
                        'timestamp' => (string) time(),
                        'type' => 'image',
                        'image' => [
                            'id' => 'META_MEDIA_ID',
                            'mime_type' => 'image/jpeg',
                            'caption' => 'photo',
                        ],
                    ]],
                ],
            ]],
        ]],
    ];

    (new ProcessWhatsAppWebhookJob($connection->id, $payload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    $message = WhatsAppMessage::where('wamid', 'wamid.image1')->firstOrFail();
    expect($message->content_type)->toBe(WhatsAppContentType::Image)
        ->and($message->content)->toBe('photo')
        ->and($message->media_mime)->toBe('image/jpeg');

    Queue::assertPushed(DownloadWhatsAppMediaJob::class);
});

test('ProcessWhatsAppWebhookJob advances outbound message status monotonically', function () {
    $connection = makeWhatsAppConnection();
    $channel = $connection->channel;
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15551112222')->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create();

    $outbound = WhatsAppMessage::factory()->outbound()->forConversation($conv)->create([
        'wamid' => 'wamid.out1',
        'status' => MessageStatus::Sent,
    ]);

    // delivered arrives first
    $deliveredPayload = [
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'statuses' => [[
                        'id' => 'wamid.out1',
                        'status' => 'delivered',
                        'recipient_id' => '15551112222',
                        'timestamp' => (string) time(),
                    ]],
                ],
            ]],
        ]],
    ];

    (new ProcessWhatsAppWebhookJob($connection->id, $deliveredPayload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    expect($outbound->fresh()->status)->toBe(MessageStatus::Delivered);

    // out-of-order 'sent' should NOT overwrite
    $sentPayload = $deliveredPayload;
    $sentPayload['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'sent';

    (new ProcessWhatsAppWebhookJob($connection->id, $sentPayload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    expect($outbound->fresh()->status)->toBe(MessageStatus::Delivered);

    // read advances monotonically
    $readPayload = $deliveredPayload;
    $readPayload['entry'][0]['changes'][0]['value']['statuses'][0]['status'] = 'read';

    (new ProcessWhatsAppWebhookJob($connection->id, $readPayload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    expect($outbound->fresh()->status)->toBe(MessageStatus::Read);
});

test('ProcessWhatsAppWebhookJob records error_code on failed status', function () {
    $connection = makeWhatsAppConnection();
    $channel = $connection->channel;
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15551112222')->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create();

    $outbound = WhatsAppMessage::factory()->outbound()->forConversation($conv)->create([
        'wamid' => 'wamid.fail1',
        'status' => MessageStatus::Sent,
    ]);

    $payload = [
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'statuses' => [[
                        'id' => 'wamid.fail1',
                        'status' => 'failed',
                        'recipient_id' => '15551112222',
                        'timestamp' => (string) time(),
                        'errors' => [[
                            'code' => 131056,
                            'message' => 'Rate limit hit',
                        ]],
                    ]],
                ],
            ]],
        ]],
    ];

    (new ProcessWhatsAppWebhookJob($connection->id, $payload))
        ->handle(app(WhatsAppWebhookParser::class), app(WhatsAppConversationResolver::class));

    $fresh = $outbound->fresh();
    expect($fresh->status)->toBe(MessageStatus::Failed)
        ->and($fresh->error_code)->toBe(131056)
        ->and($fresh->error_message)->toBe('Rate limit hit');
});

test('WhatsAppWebhookParser parses location messages', function () {
    $parsed = app(WhatsAppWebhookParser::class)->parse([
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messages' => [[
                        'id' => 'wamid.loc',
                        'from' => '15551112222',
                        'timestamp' => '100',
                        'type' => 'location',
                        'location' => [
                            'latitude' => 19.4326,
                            'longitude' => -99.1332,
                            'name' => 'Mexico City',
                        ],
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($parsed['messages'])->toHaveCount(1);
    expect($parsed['messages'][0]['content_type'])->toBe(WhatsAppContentType::Location);
    expect($parsed['messages'][0]['content'])->toBe('Mexico City');
});

test('WhatsAppWebhookParser flags reaction messages for skip', function () {
    $parsed = app(WhatsAppWebhookParser::class)->parse([
        'entry' => [[
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messages' => [[
                        'id' => 'wamid.react',
                        'from' => '15551112222',
                        'timestamp' => '100',
                        'type' => 'reaction',
                        'reaction' => ['message_id' => 'wamid.other', 'emoji' => '❤'],
                    ]],
                ],
            ]],
        ]],
    ]);

    expect($parsed['messages'][0]['skip'])->toBeTrue();
});

test('WhatsAppConversationResolver reuses an open conversation', function () {
    $channel = Channel::factory()->whatsapp()->active()->create();
    $contact = Contact::factory()->forChannel($channel)->create();
    $existing = WhatsAppConversation::factory()->forChannel($channel, $contact)->create([
        'status' => ConversationStatus::Open,
    ]);

    $resolved = app(WhatsAppConversationResolver::class)->resolveOpen($channel, $contact);

    expect($resolved->id)->toBe($existing->id);
});

test('WhatsAppConversationResolver creates a new conversation when latest is resolved >24h ago', function () {
    $channel = Channel::factory()->whatsapp()->active()->create();
    $contact = Contact::factory()->forChannel($channel)->create();
    $old = WhatsAppConversation::factory()->forChannel($channel, $contact)->create([
        'status' => ConversationStatus::Resolved,
        'last_inbound_at' => now()->subDays(5),
        'created_at' => now()->subDays(5),
    ]);

    $resolved = app(WhatsAppConversationResolver::class)->resolveOpen($channel, $contact);

    expect($resolved->id)->not->toBe($old->id);
    expect($resolved->status)->toBe(ConversationStatus::Open);
});
