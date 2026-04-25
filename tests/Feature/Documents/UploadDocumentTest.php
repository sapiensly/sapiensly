<?php

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Storage::fake('local');
});

test('an uploaded .html file is classified as an Artifact document', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent(
        'landing.html',
        '<!doctype html><html><body><h1>Hi</h1></body></html>',
    );

    actingAs($user)
        ->post('/documents', [
            'file' => $file,
            'name' => 'Landing page',
            'visibility' => 'private',
        ])
        ->assertRedirect();

    $doc = Document::where('user_id', $user->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->type)->toBe(DocumentType::Artifact)
        ->and($doc->original_filename)->toBe('landing.html')
        ->and($doc->file_path)->not->toBeNull();
});

test('an uploaded .htm file is also classified as Artifact', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent(
        'legacy.htm',
        '<html><body>legacy</body></html>',
    );

    actingAs($user)
        ->post('/documents', [
            'file' => $file,
            'visibility' => 'private',
        ])
        ->assertRedirect();

    $doc = Document::where('user_id', $user->id)->first();
    expect($doc->type)->toBe(DocumentType::Artifact);
});

test('Show page hydrates body from the uploaded file so the artifact viewer mounts', function () {
    $user = User::factory()->create();
    $html = '<!doctype html><html><body><h1>From disk</h1></body></html>';
    $file = UploadedFile::fake()->createWithContent('page.html', $html);

    actingAs($user)->post('/documents', [
        'file' => $file,
        'visibility' => 'private',
    ]);

    $doc = Document::where('user_id', $user->id)->first();
    // Sanity-check: the body column is untouched for uploaded artifacts.
    expect($doc->body)->toBeNull()
        ->and($doc->file_path)->not->toBeNull();

    actingAs($user)
        ->get("/documents/{$doc->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documents/Show')
            ->where('document.body', $html));
});

test('Edit page also hydrates body from the uploaded file for the workbench', function () {
    $user = User::factory()->create();
    $html = '<html><body>editable</body></html>';
    $file = UploadedFile::fake()->createWithContent('edit.html', $html);

    actingAs($user)->post('/documents', [
        'file' => $file,
        'visibility' => 'private',
    ]);

    $doc = Document::where('user_id', $user->id)->first();

    actingAs($user)
        ->get("/documents/{$doc->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documents/Edit')
            ->where('document.body', $html));
});

test('public share URL serves the file body for an uploaded artifact', function () {
    $user = User::factory()->create();
    $html = '<!doctype html><html><body><h1>Public file</h1></body></html>';
    $file = UploadedFile::fake()->createWithContent('public.html', $html);

    actingAs($user)->post('/documents', [
        'file' => $file,
        'visibility' => 'public',
    ]);

    $doc = Document::where('user_id', $user->id)->first();
    expect($doc->isPublic())->toBeTrue()
        ->and($doc->body)->toBeNull();

    // Public route is unauthenticated — hit it without actingAs.
    $this->get("/share/d/{$doc->id}")
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertSee('<h1>Public file</h1>', false);
});

test('public share URL returns 404 when the document is private', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent(
        'secret.html',
        '<html><body>secret</body></html>',
    );

    actingAs($user)->post('/documents', [
        'file' => $file,
        'visibility' => 'private',
    ]);

    $doc = Document::where('user_id', $user->id)->first();

    $this->get("/share/d/{$doc->id}")->assertNotFound();
});

test('updateInline on a file-backed artifact writes to the file, not the body column', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent(
        'page.html',
        '<html><body>old</body></html>',
    );

    actingAs($user)->post('/documents', [
        'file' => $file,
        'visibility' => 'private',
    ]);

    $doc = Document::where('user_id', $user->id)->first();

    actingAs($user)
        ->patch("/documents/{$doc->id}/inline", [
            'name' => $doc->name,
            'body' => '<html><body>new</body></html>',
        ])
        ->assertRedirect();

    $doc->refresh();
    // File-backed edit writes to disk, not to the DB column.
    expect($doc->body)->toBeNull();

    $fresh = app(DocumentService::class)->readFileBody($doc);
    expect($fresh)->toContain('<body>new</body>');
});
