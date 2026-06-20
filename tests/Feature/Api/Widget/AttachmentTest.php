<?php

use App\Enums\ChatbotStatus;
use App\Models\Chatbot;
use App\Models\ChatbotApiToken;
use App\Models\User;
use App\Models\WidgetAttachment;
use App\Models\WidgetConversation;
use App\Models\WidgetMessage;
use App\Models\WidgetSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->chatbot = Chatbot::factory()->create([
        'user_id' => $this->user->id,
        'status' => ChatbotStatus::Active,
    ]);
    $this->token = ChatbotApiToken::create([
        'chatbot_id' => $this->chatbot->id,
        'name' => 'Test Token',
        'token' => ChatbotApiToken::generateToken(),
        'abilities' => ['chat', 'feedback'],
    ]);
    $this->session = WidgetSession::create([
        'chatbot_id' => $this->chatbot->id,
        'session_token' => 'test-session-token',
    ]);
    $this->conversation = WidgetConversation::create([
        'chatbot_id' => $this->chatbot->id,
        'widget_session_id' => $this->session->id,
    ]);
});

function configureWidgetS3(): void
{
    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.bucket' => 'test-bucket',
    ]);
    Storage::fake('s3');
}

it('uploads an image attachment to the cloud disk', function () {
    configureWidgetS3();

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/attachments",
        ['file' => UploadedFile::fake()->image('photo.png')],
        ['Authorization' => "Bearer {$this->token->token}"],
    )
        ->assertCreated()
        ->assertJsonStructure(['id', 'original_name', 'mime', 'kind', 'size_bytes', 'url'])
        ->assertJsonPath('kind', 'image');

    $attachment = WidgetAttachment::where('widget_conversation_id', $this->conversation->id)->firstOrFail();
    expect($attachment->disk)->toBe('s3')
        ->and($attachment->kind)->toBe('image')
        ->and($attachment->extracted_text)->toBeNull();
    Storage::disk('s3')->assertExists($attachment->storage_path);
});

it('extracts text from a document upload', function () {
    configureWidgetS3();

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/attachments",
        ['file' => UploadedFile::fake()->createWithContent('notes.txt', 'The order number is 12345.')],
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertCreated()->assertJsonPath('kind', 'document');

    $attachment = WidgetAttachment::where('widget_conversation_id', $this->conversation->id)->firstOrFail();
    expect($attachment->extracted_text)->toContain('order number is 12345');
});

it('refuses the upload with 503 when no cloud disk is configured', function () {
    config([
        'filesystems.disks.s3.key' => null,
        'filesystems.disks.s3.secret' => null,
        'filesystems.disks.s3.bucket' => null,
    ]);

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/attachments",
        ['file' => UploadedFile::fake()->image('photo.png')],
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertStatus(503);
});

it('rejects a disallowed mime type', function () {
    configureWidgetS3();

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/attachments",
        ['file' => UploadedFile::fake()->create('malware.exe', 10)],
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertStatus(422);
});

it('serves an uploaded attachment back', function () {
    configureWidgetS3();
    $attachment = WidgetAttachment::factory()->create([
        'widget_conversation_id' => $this->conversation->id,
        'disk' => 's3',
        'storage_path' => "widget_uploads/{$this->conversation->id}/file.txt",
    ]);
    Storage::disk('s3')->put($attachment->storage_path, 'hello widget');

    $this->get(
        "/api/widget/v1/conversations/{$this->conversation->id}/attachments/{$attachment->id}",
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertOk();
});

it('links pre-uploaded attachments to the sent message', function () {
    configureWidgetS3();
    $attachment = WidgetAttachment::factory()->create([
        'widget_conversation_id' => $this->conversation->id,
        'widget_message_id' => null,
    ]);

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/messages",
        ['content' => 'See attached', 'attachment_ids' => [$attachment->id]],
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertCreated();

    $message = WidgetMessage::where('widget_conversation_id', $this->conversation->id)->latest('created_at')->firstOrFail();
    expect($attachment->fresh()->widget_message_id)->toBe($message->id);
});

it('allows an attachment-only message (no text)', function () {
    configureWidgetS3();
    $attachment = WidgetAttachment::factory()->create([
        'widget_conversation_id' => $this->conversation->id,
    ]);

    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/messages",
        ['attachment_ids' => [$attachment->id]],
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertCreated();
});

it('rejects an empty message with no text and no attachments', function () {
    $this->postJson(
        "/api/widget/v1/conversations/{$this->conversation->id}/messages",
        [],
        ['Authorization' => "Bearer {$this->token->token}"],
    )->assertStatus(422);
});
