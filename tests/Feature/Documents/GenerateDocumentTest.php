<?php

use App\Jobs\StreamDocumentLlmJob;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

function seedDefaultAiProvider(User $user): AiProvider
{
    return AiProvider::create([
        'user_id' => $user->id,
        'name' => 'Default',
        'display_name' => 'Default',
        'driver' => 'openai',
        'credentials' => ['api_key' => 'sk-test'],
        'models' => [
            ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o mini', 'capabilities' => ['chat']],
        ],
        'is_default' => true,
        'status' => 'active',
    ]);
}

test('generate endpoint refuses when no AI provider is configured', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/documents/generate', [
            'type' => 'md',
            'prompt' => 'Write a brief markdown memo about quarterly OKRs.',
        ])
        ->assertStatus(422)
        ->assertJson([
            'success' => false,
        ]);
});

test('generate endpoint validates the payload', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson('/documents/generate', [
            'type' => 'md',
            'prompt' => 'abc', // too short
        ])
        ->assertStatus(422);
});

test('generate endpoint rejects a non-inline document type', function () {
    $user = User::factory()->create();
    seedDefaultAiProvider($user);

    actingAs($user)
        ->postJson('/documents/generate', [
            'type' => 'url', // not an inline-authorable type
            'prompt' => 'A long-enough prompt here.',
        ])
        ->assertStatus(422)
        ->assertJson(['success' => false]);
});

test('generate endpoint dispatches a stream job and returns a streamId', function () {
    Queue::fake();

    $user = User::factory()->create();
    seedDefaultAiProvider($user);

    $response = actingAs($user)
        ->postJson('/documents/generate', [
            'type' => 'artifact',
            'prompt' => 'A minimalist single-page landing with a dark hero and three feature cards.',
        ])
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    $streamId = $response->json('streamId');
    expect($streamId)->toBeString()->and($streamId)->toStartWith('docstream_');

    // Auth cache entry is written so the Reverb channel guard can match.
    expect(Cache::get("document-stream:{$streamId}"))->toBe($user->id);

    Queue::assertPushed(StreamDocumentLlmJob::class, function ($job) use ($streamId, $user) {
        return $job->streamId === $streamId
            && $job->userId === $user->id
            && $job->mode === 'generate'
            && $job->type === 'artifact';
    });
});

test('refine endpoint dispatches a refine-mode stream job with history and body', function () {
    Queue::fake();

    $user = User::factory()->create();
    seedDefaultAiProvider($user);

    $response = actingAs($user)
        ->postJson('/documents/refine', [
            'type' => 'artifact',
            'instruction' => 'Make the header sticky with a navy background.',
            'currentBody' => '<!doctype html><html><body><h1>Hi</h1></body></html>',
            'history' => [
                ['role' => 'user', 'content' => 'Add a CTA button.'],
                ['role' => 'assistant', 'content' => 'Updated.'],
            ],
        ])
        ->assertStatus(200)
        ->assertJson(['success' => true]);

    $streamId = $response->json('streamId');

    Queue::assertPushed(StreamDocumentLlmJob::class, function ($job) use ($streamId) {
        return $job->streamId === $streamId
            && $job->mode === 'refine'
            && $job->currentBody !== null
            && count($job->history) === 2;
    });
});
