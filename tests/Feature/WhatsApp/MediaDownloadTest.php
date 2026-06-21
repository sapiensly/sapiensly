<?php

use App\Enums\MessageDirection;
use App\Enums\MessageRole;
use App\Enums\WhatsAppContentType;
use App\Jobs\DownloadWhatsAppMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CloudProviderService;
use App\Services\WhatsApp\WhatsAppProviderContract;
use Illuminate\Support\Facades\Storage;

function makeWhatsAppMediaMessage(WhatsAppContentType $type, string $mime): WhatsAppMessage
{
    $organization = Organization::create(['name' => 'WA Co', 'slug' => 'wa-co-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->whatsapp()->active()->forOrganization($organization, $user)->create();
    $connection = WhatsAppConnection::factory()->forChannel($channel)->create();
    $contact = Contact::factory()->forChannel($channel)->whatsapp('15551112222')->create();
    $conv = WhatsAppConversation::factory()->forChannel($channel, $contact)->create();

    return WhatsAppMessage::factory()->forConversation($conv)->create([
        'role' => MessageRole::User,
        'direction' => MessageDirection::Inbound,
        'content_type' => $type,
        'media_mime' => $mime,
    ]);
}

function runDownloadJob(WhatsAppMessage $message, string $bytes): void
{
    $provider = Mockery::mock(WhatsAppProviderContract::class);
    $provider->shouldReceive('downloadMedia')->andReturn($bytes);

    (new DownloadWhatsAppMediaJob($message->id, 'media-123'))
        ->handle($provider, app(CloudProviderService::class));
}

it('persists the disk name and path for downloaded media', function () {
    Storage::fake('documents');
    $message = makeWhatsAppMediaMessage(WhatsAppContentType::Image, 'image/png');

    runDownloadJob($message, 'fake-png-bytes');

    $message->refresh();
    expect($message->media_disk)->toBe('documents')
        ->and($message->media_local_path)->not->toBeNull();
    Storage::disk('documents')->assertExists($message->media_local_path);
});

it('extracts text from a downloaded document', function () {
    Storage::fake('documents');
    $message = makeWhatsAppMediaMessage(WhatsAppContentType::Document, 'text/plain');

    runDownloadJob($message, 'The order id is 999.');

    expect($message->refresh()->metadata['extracted_text'] ?? null)
        ->toContain('order id is 999');
});

it('leaves extracted text empty for an image', function () {
    Storage::fake('documents');
    $message = makeWhatsAppMediaMessage(WhatsAppContentType::Image, 'image/png');

    runDownloadJob($message, 'fake-png-bytes');

    expect($message->refresh()->metadata['extracted_text'] ?? null)->toBeNull();
});
