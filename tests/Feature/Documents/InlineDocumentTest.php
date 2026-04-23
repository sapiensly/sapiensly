<?php

use App\Enums\DocumentType;
use App\Enums\EmbeddingStatus;
use App\Enums\Visibility;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('storeInline creates a markdown document with body and no file_path', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'md',
            'name' => 'Release notes',
            'body' => "# Release\n\n- item 1\n- item 2",
        ])
        ->assertRedirect();

    $doc = Document::where('user_id', $user->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->type)->toBe(DocumentType::Md)
        ->and($doc->body)->toContain('# Release')
        ->and($doc->file_path)->toBeNull()
        ->and($doc->isInline())->toBeTrue();
});

test('storeInline accepts plain text and artifact types', function () {
    $user = User::factory()->create();

    foreach (['txt' => 'hello world', 'artifact' => '<h1>hi</h1>'] as $type => $body) {
        $this->actingAs($user)
            ->post('/documents/inline', [
                'type' => $type,
                'name' => "Sample {$type}",
                'body' => $body,
            ])
            ->assertRedirect();
    }

    expect(Document::where('type', 'txt')->count())->toBe(1)
        ->and(Document::where('type', 'artifact')->count())->toBe(1);
});

test('storeInline rejects non-inline types like pdf', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'pdf',
            'name' => 'Nope',
            'body' => 'ignored',
        ])
        ->assertSessionHasErrors(['type']);
});

test('storeInline rejects empty body and oversized body', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'md',
            'name' => 'Empty',
            'body' => '',
        ])
        ->assertSessionHasErrors(['body']);

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'md',
            'name' => 'Too big',
            'body' => str_repeat('x', 10_485_761),
        ])
        ->assertSessionHasErrors(['body']);
});

test('storeInline sets private visibility by default for a lone user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'md',
            'name' => 'Mine',
            'body' => 'hello',
        ])
        ->assertRedirect();

    $doc = Document::where('user_id', $user->id)->firstOrFail();
    expect($doc->visibility)->toBe(Visibility::Private);
});

test('storeInline attaches to knowledge base and dispatches the processing job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $kb = KnowledgeBase::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'md',
            'name' => 'KB doc',
            'body' => 'Some searchable content.',
            'knowledge_base_id' => $kb->id,
        ])
        ->assertRedirect();

    $doc = Document::where('user_id', $user->id)->firstOrFail();
    expect($doc->knowledgeBases()->count())->toBe(1)
        ->and($doc->knowledgeBases()->first()->pivot->embedding_status)
        ->toBe(EmbeddingStatus::Pending->value);

    Queue::assertPushed(ProcessDocumentForKnowledgeBase::class);
});

test('update can modify the body of an inline artifact', function () {
    $user = User::factory()->create();
    $doc = Document::create([
        'user_id' => $user->id,
        'organization_id' => null,
        'name' => 'Widget',
        'type' => DocumentType::Artifact,
        'body' => '<h1>Old</h1>',
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user)
        ->put("/documents/{$doc->id}", [
            'body' => '<h1>New</h1><script>console.log("ok")</script>',
        ])
        ->assertRedirect();

    expect($doc->fresh()->body)->toContain('<h1>New</h1>');
});

test('update rejects body edits on uploaded (non-inline) documents', function () {
    $user = User::factory()->create();
    $doc = Document::create([
        'user_id' => $user->id,
        'organization_id' => null,
        'name' => 'Uploaded',
        'type' => DocumentType::Pdf,
        'file_path' => "{$user->id}/documents/x/file.pdf",
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user)
        ->put("/documents/{$doc->id}", [
            'body' => 'not allowed',
        ])
        ->assertSessionHasErrors(['body']);

    expect($doc->fresh()->body)->toBeNull();
});

test('public share route serves a public artifact with CSP sandbox header', function () {
    $owner = User::factory()->create();
    $doc = Document::create([
        'user_id' => $owner->id,
        'organization_id' => null,
        'name' => 'Party page',
        'type' => DocumentType::Artifact,
        'body' => '<!doctype html><html><body><h1>Welcome</h1></body></html>',
        'visibility' => Visibility::Public,
    ]);

    $response = $this->get("/share/d/{$doc->id}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $response->assertHeader('Content-Security-Policy', 'sandbox allow-scripts allow-forms allow-popups');
    expect($response->getContent())->toContain('<h1>Welcome</h1>');
});

test('public share route 404s when document is not public', function () {
    $owner = User::factory()->create();
    $doc = Document::create([
        'user_id' => $owner->id,
        'organization_id' => null,
        'name' => 'Hidden',
        'type' => DocumentType::Artifact,
        'body' => '<h1>secret</h1>',
        'visibility' => Visibility::Private,
    ]);

    $this->get("/share/d/{$doc->id}")->assertNotFound();
});

test('public share route 404s for non-artifact documents', function () {
    $owner = User::factory()->create();
    $doc = Document::create([
        'user_id' => $owner->id,
        'organization_id' => null,
        'name' => 'Md doc',
        'type' => DocumentType::Md,
        'body' => '# hi',
        'visibility' => Visibility::Public,
    ]);

    $this->get("/share/d/{$doc->id}")->assertNotFound();
});

test('storeInline rejects Public visibility for non-artifact types', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'md',
            'name' => 'Markdown goes public?',
            'body' => '# hi',
            'visibility' => 'public',
        ])
        ->assertSessionHasErrors(['visibility']);
});

test('storeInline accepts Public visibility for artifact type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/documents/inline', [
            'type' => 'artifact',
            'name' => 'Public artifact',
            'body' => '<h1>hello</h1>',
            'visibility' => 'public',
        ])
        ->assertRedirect();

    $doc = Document::where('user_id', $user->id)->firstOrFail();
    expect($doc->visibility)->toBe(Visibility::Public)
        ->and($doc->isPublic())->toBeTrue();
});

test('updateVisibility rejects Public for non-artifact documents', function () {
    $user = User::factory()->create();
    $doc = Document::create([
        'user_id' => $user->id,
        'organization_id' => null,
        'name' => 'Md',
        'type' => DocumentType::Md,
        'body' => '# hi',
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user)
        ->put("/documents/{$doc->id}", ['visibility' => 'public'])
        ->assertSessionHasErrors(['visibility']);

    expect($doc->fresh()->visibility)->toBe(Visibility::Private);
});

test('Show renders the inline body via Inertia props', function () {
    $user = User::factory()->create();
    $doc = Document::create([
        'user_id' => $user->id,
        'organization_id' => null,
        'name' => 'Hello doc',
        'type' => DocumentType::Md,
        'body' => '# Hello',
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($user)
        ->get("/documents/{$doc->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documents/Show')
            ->where('document.body', '# Hello')
            ->where('document.type', 'md'));
});
