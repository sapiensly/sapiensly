<?php

use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->chat = Chat::factory()->forUser($this->user)->create();
});

function configureS3(): void
{
    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.bucket' => 'test-bucket',
    ]);
    Storage::fake('s3');
}

it('uploads a chat attachment to the cloud disk', function () {
    configureS3();

    $this->actingAs($this->user)
        ->post(route('chat.attachments.upload', $this->chat), [
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'original_name', 'mime', 'size_bytes', 'url']);

    $attachment = ChatAttachment::where('chat_id', $this->chat->id)->firstOrFail();
    expect($attachment->disk)->toBe('s3')
        ->and($attachment->original_name)->toBe('photo.png');
    Storage::disk('s3')->assertExists($attachment->storage_path);
});

it('refuses the upload with 503 when no cloud disk is configured', function () {
    config([
        'filesystems.disks.s3.key' => null,
        'filesystems.disks.s3.secret' => null,
        'filesystems.disks.s3.bucket' => null,
    ]);

    $this->actingAs($this->user)
        ->post(route('chat.attachments.upload', $this->chat), [
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertStatus(503);
});

it('forbids uploading to another user\'s chat', function () {
    configureS3();
    $other = User::factory()->create();

    $this->actingAs($other)
        ->post(route('chat.attachments.upload', $this->chat), [
            'file' => UploadedFile::fake()->image('photo.png'),
        ])
        ->assertForbidden();
});

it('serves an attachment only to its owner', function () {
    configureS3();
    $attachment = ChatAttachment::factory()->create([
        'chat_id' => $this->chat->id,
        'user_id' => $this->user->id,
        'disk' => 's3',
        'storage_path' => "chat_uploads/{$this->chat->id}/file.txt",
    ]);
    Storage::disk('s3')->put($attachment->storage_path, 'hello');

    $this->actingAs($this->user)
        ->get(route('chat.attachments.show', ['chat' => $this->chat->id, 'attachment' => $attachment->id]))
        ->assertOk();

    $other = User::factory()->create();
    $this->actingAs($other)
        ->get(route('chat.attachments.show', ['chat' => $this->chat->id, 'attachment' => $attachment->id]))
        ->assertNotFound();
});
