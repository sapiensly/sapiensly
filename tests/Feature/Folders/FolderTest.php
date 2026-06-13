<?php

use App\Enums\Visibility;
use App\Models\Document;
use App\Models\Folder;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates a root folder', function () {
    $this->actingAs($this->user)
        ->post(route('folders.store'), ['name' => 'Manuals'])
        ->assertRedirect();

    expect(Folder::where('name', 'Manuals')->where('user_id', $this->user->id)->exists())
        ->toBeTrue();
});

it('creates a nested folder validating the existing parent', function () {
    $parent = Folder::create([
        'user_id' => $this->user->id,
        'name' => 'Parent',
        'visibility' => Visibility::Private,
    ]);

    $this->actingAs($this->user)
        ->post(route('folders.store'), [
            'name' => 'Child',
            'parent_id' => $parent->id,
        ])
        ->assertRedirect();

    expect(Folder::where('name', 'Child')->value('parent_id'))->toBe($parent->id);
});

it('rejects a parent_id that does not exist', function () {
    $this->actingAs($this->user)
        ->post(route('folders.store'), [
            'name' => 'Orphan',
            'parent_id' => 'folder_missing',
        ])
        ->assertSessionHasErrors('parent_id');
});

it('moves a document into an existing folder', function () {
    $folder = Folder::create([
        'user_id' => $this->user->id,
        'name' => 'Archive',
        'visibility' => Visibility::Private,
    ]);
    $document = Document::create([
        'user_id' => $this->user->id,
        'name' => 'Note',
        'original_filename' => 'note.md',
        'type' => 'md',
        'file_size' => 1,
        'visibility' => Visibility::Private,
        'body' => '# Note',
    ]);

    $this->actingAs($this->user)
        ->post(route('documents.move', $document), ['folder_id' => $folder->id])
        ->assertRedirect();

    expect($document->fresh()->folder_id)->toBe($folder->id);
});
