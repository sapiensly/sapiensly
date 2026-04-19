<?php

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\MessageStatus;
use App\Enums\WhatsAppContentType;
use App\Jobs\GenerateWhatsAppReplyJob;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\LLMService;
use App\Services\WhatsApp\WhatsAppReplyOrchestrator;
use Illuminate\Support\Facades\Queue;

function makeWhatsAppAgentStack(): array
{
    $user = User::factory()->create();
    $agent = Agent::factory()->standalone()->active()->forUser($user)->create();
    $channel = Channel::factory()->whatsapp()->active()->forUser($user)->create([
        'agent_id' => $agent->id,
    ]);
    $connection = WhatsAppConnection::factory()->forChannel($channel)->create();
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15551112222')->recentlyActive()->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create([
        'status' => ConversationStatus::Open,
        'last_inbound_at' => now(),
    ]);

    WhatsAppMessage::factory()->forConversation($conv)->create([
        'role' => MessageRole::User,
        'direction' => MessageDirection::Inbound,
        'content' => 'hola cómo estás',
        'content_type' => WhatsAppContentType::Text,
    ]);

    return [$user, $agent, $channel, $connection, $contact, $conv];
}

test('orchestrator runs a single agent reply and queues the send job', function () {
    Queue::fake();
    [, , , , , $conv] = makeWhatsAppAgentStack();

    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('chat')
        ->once()
        ->andReturn('¡Hola! ¿En qué puedo ayudarte?');
    $this->app->instance(LLMService::class, $llm);

    app(WhatsAppReplyOrchestrator::class)->reply($conv);

    $assistant = WhatsAppMessage::where('whatsapp_conversation_id', $conv->id)
        ->where('direction', MessageDirection::Outbound)
        ->first();

    expect($assistant)->not->toBeNull()
        ->and($assistant->content)->toBe('¡Hola! ¿En qué puedo ayudarte?')
        ->and($assistant->status)->toBe(MessageStatus::Pending);

    Queue::assertPushed(SendWhatsAppMessageJob::class, 1);
});

test('orchestrator skips when channel has no agent or team target', function () {
    Queue::fake();
    $channel = Channel::factory()->whatsapp()->active()->create();
    WhatsAppConnection::factory()->forChannel($channel)->create();
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15550000000')->recentlyActive()->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create();
    WhatsAppMessage::factory()->forConversation($conv)->create();

    app(WhatsAppReplyOrchestrator::class)->reply($conv);

    Queue::assertNotPushed(SendWhatsAppMessageJob::class);
});

test('orchestrator does not send when the LLM returns an empty reply', function () {
    Queue::fake();
    [, , , , , $conv] = makeWhatsAppAgentStack();

    $llm = Mockery::mock(LLMService::class);
    $llm->shouldReceive('chat')->andReturn('');
    $this->app->instance(LLMService::class, $llm);

    app(WhatsAppReplyOrchestrator::class)->reply($conv);

    Queue::assertNotPushed(SendWhatsAppMessageJob::class);
});

test('GenerateWhatsAppReplyJob skips conversations in escalated status', function () {
    Queue::fake();
    [, , , , , $conv] = makeWhatsAppAgentStack();
    $conv->update(['status' => ConversationStatus::Escalated]);

    $orchestrator = Mockery::mock(WhatsAppReplyOrchestrator::class);
    $orchestrator->shouldNotReceive('reply');

    (new GenerateWhatsAppReplyJob($conv->id))->handle($orchestrator);
});

test('GenerateWhatsAppReplyJob flips pending conversations to open before replying', function () {
    Queue::fake();
    [, , , , , $conv] = makeWhatsAppAgentStack();
    $conv->update(['status' => ConversationStatus::Pending]);

    $orchestrator = Mockery::mock(WhatsAppReplyOrchestrator::class);
    $orchestrator->shouldReceive('reply')->once();

    (new GenerateWhatsAppReplyJob($conv->id))->handle($orchestrator);

    expect($conv->fresh()->status)->toBe(ConversationStatus::Open);
});

test('GenerateWhatsAppReplyJob is a no-op for missing conversations', function () {
    $orchestrator = Mockery::mock(WhatsAppReplyOrchestrator::class);
    $orchestrator->shouldNotReceive('reply');

    (new GenerateWhatsAppReplyJob('whatsapp_conversations_missing_id'))->handle($orchestrator);
});
