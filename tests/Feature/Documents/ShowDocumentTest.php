<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');
});

test('show hydrates the body of a file-backed markdown document', function () {
    $user = User::factory()->create();
    $markdown = "# Company context\n\n- one\n- two";

    actingAs($user)->post('/documents', [
        'file' => UploadedFile::fake()->createWithContent('context.md', $markdown),
        'name' => 'Company context',
        'visibility' => 'private',
    ])->assertRedirect();

    $document = $user->documents()->firstOrFail();

    expect($document->body)->toBeNull()
        ->and($document->file_path)->not->toBeNull();

    actingAs($user)
        ->get(route('documents.show', $document))
        ->assertInertia(fn ($page) => $page
            ->component('documents/Show')
            ->where('document.type', 'md')
            ->where('document.body', $markdown)
        );
});
