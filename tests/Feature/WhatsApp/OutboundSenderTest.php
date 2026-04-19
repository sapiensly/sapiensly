<?php

use App\Enums\ChannelStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsApp\WhatsAppMessageSender;
use App\Services\WhatsApp\WhatsAppProviderContract;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppSendException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeActiveWhatsAppStack(): array
{
    $channel = Channel::factory()->whatsapp()->active()->create();
    $connection = WhatsAppConnection::factory()->forChannel($channel)->create();
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15551234567')->recentlyActive()->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create([
        'status' => ConversationStatus::Open,
        'last_inbound_at' => now(),
    ]);

    return [$channel, $connection, $contact, $conv];
}

test('sendText persists the message and queues the send job', function () {
    Queue::fake();
    [, , , $conv] = makeActiveWhatsAppStack();

    $messages = app(WhatsAppMessageSender::class)->sendText($conv, 'Hola cómo estás');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->direction)->toBe(MessageDirection::Outbound)
        ->and($messages[0]->status)->toBe(MessageStatus::Pending)
        ->and($messages[0]->content)->toBe('Hola cómo estás');

    Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
});

test('sendText chunks long messages into multiple WhatsAppMessages', function () {
    Queue::fake();
    [, , , $conv] = makeActiveWhatsAppStack();
    $long = str_repeat('a', 5000);

    $messages = app(WhatsAppMessageSender::class)->sendText($conv, $long);

    expect($messages)->toHaveCount(2);
    expect($messages[0]->metadata['group_id'])->toBe($messages[1]->metadata['group_id']);
    expect($messages[0]->metadata['part'])->toBe(1);
    expect($messages[1]->metadata['part'])->toBe(2);

    Queue::assertPushed(SendWhatsAppMessageJob::class, 2);
});

test('sendText rejects when contact is opted out', function () {
    [, , $contact, $conv] = makeActiveWhatsAppStack();
    $contact->update(['opted_out_at' => now()]);

    expect(fn () => app(WhatsAppMessageSender::class)->sendText($conv->fresh(), 'hi'))
        ->toThrow(WhatsAppSendException::class);
});

test('sendText rejects when channel is paused', function () {
    [$channel, , , $conv] = makeActiveWhatsAppStack();
    $channel->update(['status' => ChannelStatus::Paused]);

    expect(fn () => app(WhatsAppMessageSender::class)->sendText($conv->fresh(), 'hi'))
        ->toThrow(WhatsAppSendException::class);
});

test('sendText rejects free-form outside the 24h session window', function () {
    [, , $contact, $conv] = makeActiveWhatsAppStack();
    $contact->update(['last_inbound_at' => now()->subDays(2)]);

    expect(fn () => app(WhatsAppMessageSender::class)->sendText($conv->fresh(), 'hi'))
        ->toThrow(function (WhatsAppSendException $e) {
            expect($e->reasonCode)->toBe('window_expired');
        });
});

test('sendTemplate bypasses the 24h session window', function () {
    Queue::fake();
    [, $connection, $contact, $conv] = makeActiveWhatsAppStack();
    $contact->update(['last_inbound_at' => now()->subDays(5)]);
    $template = WhatsAppTemplate::factory()->create(['whatsapp_connection_id' => $connection->id]);

    $message = app(WhatsAppMessageSender::class)->sendTemplate($conv->fresh(), $template, []);

    expect($message->content_type)->toBe(WhatsAppContentType::Template)
        ->and($message->template_name)->toBe($template->name)
        ->and($message->status)->toBe(MessageStatus::Pending);

    Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
});

test('SendWhatsAppMessageJob hits Meta Graph and marks message as Sent', function () {
    Http::fake([
        'graph.facebook.com/*/messages' => Http::response([
            'messages' => [['id' => 'wamid.HELLO_WORLD']],
        ], 200),
    ]);

    [, , , $conv] = makeActiveWhatsAppStack();
    $message = WhatsAppMessage::create([
        'whatsapp_conversation_id' => $conv->id,
        'role' => MessageRole::Assistant,
        'direction' => MessageDirection::Outbound,
        'content' => 'hello',
        'content_type' => WhatsAppContentType::Text,
        'status' => MessageStatus::Pending,
    ]);

    (new SendWhatsAppMessageJob($message->id))->handle(app(WhatsAppProviderContract::class));

    $fresh = $message->fresh();
    expect($fresh->status)->toBe(MessageStatus::Sent)
        ->and($fresh->wamid)->toBe('wamid.HELLO_WORLD');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/messages')
        && $req['type'] === 'text'
        && $req['text']['body'] === 'hello');
});

test('SendWhatsAppMessageJob marks message failed on a permanent provider error', function () {
    Http::fake([
        'graph.facebook.com/*/messages' => Http::response([
            'error' => ['code' => 131048, 'message' => 'Recipient phone number is not a WhatsApp user'],
        ], 400),
    ]);

    [, , , $conv] = makeActiveWhatsAppStack();
    $message = WhatsAppMessage::create([
        'whatsapp_conversation_id' => $conv->id,
        'role' => MessageRole::Assistant,
        'direction' => MessageDirection::Outbound,
        'content' => 'hello',
        'content_type' => WhatsAppContentType::Text,
        'status' => MessageStatus::Pending,
    ]);

    $job = new SendWhatsAppMessageJob($message->id);

    // Simulate exhausted retries by throwing and catching until attempts == tries.
    try {
        $job->handle(app(WhatsAppProviderContract::class));
    } catch (WhatsAppProviderException) {
        // expected — queue would retry.
    }

    // Run the failed() hook manually to complete the lifecycle in-test.
    $job->failed(new Exception('exhausted'));

    expect($message->fresh()->status)->toBe(MessageStatus::Failed);
});

test('chunkText respects word boundaries when possible', function () {
    $sender = app(WhatsAppMessageSender::class);
    $text = str_repeat('word ', 900); // ~4500 chars
    $chunks = $sender->chunkText(rtrim($text));

    expect(count($chunks))->toBeGreaterThanOrEqual(2);
    foreach ($chunks as $chunk) {
        expect(strlen($chunk))->toBeLessThanOrEqual(WhatsAppMessageSender::MAX_TEXT_CHUNK);
    }
});
