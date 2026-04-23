<?php

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Models\Document;
use App\Models\User;

use function Pest\Laravel\actingAs;

test('owner can update an inline artifact body, name, and keywords', function () {
    $user = User::factory()->create();
    $doc = Document::create([
        'user_id' => $user->id,
        'name' => 'Old name',
        'original_filename' => 'old.html',
        'type' => DocumentType::Artifact,
        'file_size' => 0,
        'visibility' => Visibility::Private,
        'body' => '<!doctype html><html><body>Old</body></html>',
        'keywords' => ['stale'],
    ]);

    actingAs($user)
        ->patch("/documents/{$doc->id}/inline", [
            'name' => 'New shiny name',
            'body' => '<!doctype html><html><body>New</body></html>',
            'keywords' => ['fresh', 'artifact'],
        ])
        ->assertRedirect("/documents/{$doc->id}");

    $doc->refresh();
    expect($doc->name)->toBe('New shiny name');
    expect($doc->body)->toContain('<body>New</body>');
    expect($doc->keywords)->toBe(['fresh', 'artifact']);
});

test('non-owner cannot update an inline document', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $doc = Document::create([
        'user_id' => $owner->id,
        'name' => 'Owner doc',
        'original_filename' => 'doc.html',
        'type' => DocumentType::Artifact,
        'file_size' => 0,
        'visibility' => Visibility::Private,
        'body' => '<!doctype html><html></html>',
    ]);

    actingAs($intruder)
        ->patch("/documents/{$doc->id}/inline", [
            'name' => 'Hijack',
            'body' => '<html><body>pwned</body></html>',
        ])
        ->assertStatus(403);

    expect($doc->fresh()->name)->toBe('Owner doc');
});

test('updateInline validates the body length ceiling', function () {
    $user = User::factory()->create();
    $doc = Document::create([
        'user_id' => $user->id,
        'name' => 'Artifact',
        'original_filename' => 'doc.html',
        'type' => DocumentType::Artifact,
        'file_size' => 0,
        'visibility' => Visibility::Private,
        'body' => '<html></html>',
    ]);

    actingAs($user)
        ->patch("/documents/{$doc->id}/inline", [
            'name' => 'Too big',
            'body' => str_repeat('a', 10_485_761),
        ])
        ->assertSessionHasErrors('body');
});
