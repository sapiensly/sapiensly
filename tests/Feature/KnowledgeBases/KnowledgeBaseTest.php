<?php

use App\Enums\KnowledgeBaseStatus;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseDocument;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('index', function () {
    it('displays the knowledge bases index page', function () {
        $this->actingAs($this->user)
            ->get(route('knowledge-bases.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('knowledge-bases/Index'));
    });

    it('shows only knowledge bases belonging to the authenticated user', function () {
        $myKb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);
        $otherKb = KnowledgeBase::factory()->create();

        $this->actingAs($this->user)
            ->get(route('knowledge-bases.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('knowledge-bases/Index')
                ->has('knowledgeBases.data', 1)
                ->where('knowledgeBases.data.0.id', $myKb->id)
            );
    });

    it('includes document counts', function () {
        $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);
        KnowledgeBaseDocument::factory()->count(3)->create(['knowledge_base_id' => $kb->id]);

        $this->actingAs($this->user)
            ->get(route('knowledge-bases.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('knowledgeBases.data.0.documents_count', 3)
            );
    });
});

describe('create', function () {
    it('displays the create knowledge base form', function () {
        $this->actingAs($this->user)
            ->get(route('knowledge-bases.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('knowledge-bases/Create')
                ->has('documentTypes')
            );
    });
});

describe('store', function () {
    it('creates a knowledge base', function () {
        $data = [
            'name' => 'My Knowledge Base',
            'description' => 'A test knowledge base',
            'config' => [
                'chunk_size' => 1000,
                'chunk_overlap' => 200,
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('knowledge-bases.store'), $data)
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_bases', [
            'user_id' => $this->user->id,
            'name' => 'My Knowledge Base',
            'status' => KnowledgeBaseStatus::Pending->value,
        ]);
    });

    it('creates a knowledge base with default config', function () {
        $data = [
            'name' => 'Minimal KB',
        ];

        $this->actingAs($this->user)
            ->post(route('knowledge-bases.store'), $data)
            ->assertRedirect();

        $kb = KnowledgeBase::where('name', 'Minimal KB')->first();
        expect($kb->config)->toHaveKey('chunk_size');
        expect($kb->config)->toHaveKey('chunk_overlap');
    });

    it('validates required fields', function () {
        $this->actingAs($this->user)
            ->post(route('knowledge-bases.store'), [])
            ->assertSessionHasErrors(['name']);
    });

    it('validates config constraints', function () {
        $data = [
            'name' => 'Bad Config KB',
            'config' => [
                'chunk_size' => 50, // Too small, min is 100
                'chunk_overlap' => 1000, // Too large, max is 500
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('knowledge-bases.store'), $data)
            ->assertSessionHasErrors(['config.chunk_size', 'config.chunk_overlap']);
    });
});

describe('show', function () {
    it('displays a knowledge base with its documents', function () {
        $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);
        KnowledgeBaseDocument::factory()->pdf()->create(['knowledge_base_id' => $kb->id]);
        KnowledgeBaseDocument::factory()->url()->create(['knowledge_base_id' => $kb->id]);

        $this->actingAs($this->user)
            ->get(route('knowledge-bases.show', $kb))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('knowledge-bases/Show')
                ->where('knowledgeBase.id', $kb->id)
                ->has('knowledgeBase.documents', 2)
            );
    });

    it('returns 403 for knowledge bases belonging to other users', function () {
        $otherKb = KnowledgeBase::factory()->create();

        $this->actingAs($this->user)
            ->get(route('knowledge-bases.show', $otherKb))
            ->assertForbidden();
    });
});

describe('edit', function () {
    it('displays the edit form for a knowledge base', function () {
        $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->get(route('knowledge-bases.edit', $kb))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('knowledge-bases/Edit')
                ->where('knowledgeBase.id', $kb->id)
            );
    });

    it('returns 403 for knowledge bases belonging to other users', function () {
        $otherKb = KnowledgeBase::factory()->create();

        $this->actingAs($this->user)
            ->get(route('knowledge-bases.edit', $otherKb))
            ->assertForbidden();
    });
});

describe('update', function () {
    it('updates a knowledge base', function () {
        $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);

        $data = [
            'name' => 'Updated KB Name',
            'description' => 'Updated description',
            'config' => [
                'chunk_size' => 1500,
                'chunk_overlap' => 300,
            ],
        ];

        $this->actingAs($this->user)
            ->put(route('knowledge-bases.update', $kb), $data)
            ->assertRedirect();

        $kb->refresh();
        expect($kb->name)->toBe('Updated KB Name');
        expect($kb->description)->toBe('Updated description');
        expect($kb->config['chunk_size'])->toBe(1500);
    });

    it('returns 403 when updating knowledge bases belonging to other users', function () {
        $otherKb = KnowledgeBase::factory()->create();

        $this->actingAs($this->user)
            ->put(route('knowledge-bases.update', $otherKb), [
                'name' => 'Hacked',
            ])
            ->assertForbidden();
    });
});

describe('destroy', function () {
    it('soft deletes a knowledge base', function () {
        $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->delete(route('knowledge-bases.destroy', $kb))
            ->assertRedirect(route('knowledge-bases.index'));

        $this->assertSoftDeleted('knowledge_bases', ['id' => $kb->id]);
    });

    it('returns 403 when deleting knowledge bases belonging to other users', function () {
        $otherKb = KnowledgeBase::factory()->create();

        $this->actingAs($this->user)
            ->delete(route('knowledge-bases.destroy', $otherKb))
            ->assertForbidden();
    });
});
