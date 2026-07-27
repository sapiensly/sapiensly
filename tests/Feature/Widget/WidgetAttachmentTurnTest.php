<?php

use App\Enums\ChatbotStatus;
use App\Enums\MessageRole;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Models\WidgetAttachment;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use App\Services\WidgetStreamService;
use Illuminate\Support\Str;

/**
 * The file the visitor just sent is the one the bot has to read.
 *
 * turnAttachments() asked for the visitor's "last" message with `latest()` on a
 * relation that is already ordered ascending — so it got their FIRST message
 * instead. A document attached on turn three never reached the model, and turn
 * one's document was re-sent on every turn after it.
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
    ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'T',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat'],
    ]);
    $session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => Str::random(64),
    ]);
    $this->conversation = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'widget_session_id' => $session->id,
    ]);
});

function turnMessage(WidgetConversation $conversation, string $content, string $at): WidgetMessage
{
    $message = WidgetMessage::create([
        'widget_conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => $content,
    ]);
    $message->forceFill(['created_at' => $at])->save();

    return $message;
}

function attachTo(WidgetMessage $message, string $name, string $text): void
{
    WidgetAttachment::create([
        'widget_conversation_id' => $message->widget_conversation_id,
        'widget_message_id' => $message->id,
        'original_name' => $name,
        'mime' => 'application/pdf',
        'disk' => 's3',
        'storage_path' => 'widget/'.Str::lower(Str::random(8)).'.pdf',
        'size_bytes' => 1024,
        'extracted_text' => $text,
    ]);
}

/** Read the private resolver the stream path uses to gather this turn's files. */
function attachmentsForTurn(WidgetConversation $conversation): array
{
    $method = new ReflectionMethod(app(WidgetStreamService::class), 'turnAttachments');

    return $method->invoke(app(WidgetStreamService::class), $conversation);
}

it('reads the file from the turn the visitor is actually on', function () {
    $first = turnMessage($this->conversation, 'Aquí va mi contrato', '2026-07-27 10:00:00');
    attachTo($first, 'contrato-viejo.pdf', 'Cláusula del contrato viejo.');

    turnMessage($this->conversation, 'Perdón, este es el bueno', '2026-07-27 10:05:00');
    $third = turnMessage($this->conversation, 'Mira este', '2026-07-27 10:10:00');
    attachTo($third, 'contrato-nuevo.pdf', 'Cláusula del contrato nuevo.');

    $names = array_map(fn ($d) => $d['name'] ?? $d['original_name'] ?? '', attachmentsForTurn($this->conversation));

    expect($names)->toContain('contrato-nuevo.pdf')
        ->and($names)->not->toContain('contrato-viejo.pdf');
});

it('stops re-sending an old file on every later turn', function () {
    $first = turnMessage($this->conversation, 'Mi factura', '2026-07-27 10:00:00');
    attachTo($first, 'factura.pdf', 'Total: $1,000.');

    // A later turn with nothing attached must arrive with nothing attached.
    turnMessage($this->conversation, '¿Y el envío?', '2026-07-27 10:05:00');

    expect(attachmentsForTurn($this->conversation))->toBe([]);
});
