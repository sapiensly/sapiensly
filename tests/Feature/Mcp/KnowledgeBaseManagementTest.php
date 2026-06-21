<?php

use App\Enums\KnowledgeBaseStatus;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\CreateKnowledgeBaseTool;
use App\Mcp\Tools\Data\DeleteKnowledgeBaseTool;
use App\Mcp\Tools\Data\GetKnowledgeBaseTool;
use App\Mcp\Tools\Data\UpdateKnowledgeBaseTool;
use App\Models\KnowledgeBase;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

it('create_knowledge_base creates a pending KB with default chunking', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateKnowledgeBaseTool::class, [
            'name' => 'Support Manuals',
            'description' => 'Level 1 docs',
        ])
        ->assertOk()
        ->assertSee('Support Manuals')
        ->assertSee('pending');

    $kb = KnowledgeBase::where('user_id', $this->user->id)->where('name', 'Support Manuals')->first();
    expect($kb)->not->toBeNull();
    expect($kb->status)->toBe(KnowledgeBaseStatus::Pending);
    expect($kb->config['chunk_size'])->toBe(1000);
    expect($kb->config['chunk_overlap'])->toBe(200);
});

it('get_knowledge_base returns the KB with its attached documents', function () {
    $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);

    SapiensServer::actingAs($this->user)
        ->tool(GetKnowledgeBaseTool::class, ['knowledge_base_id' => $kb->id])
        ->assertOk()
        ->assertSee($kb->id)
        ->assertSee('documents');
});

it('update_knowledge_base applies a partial update', function () {
    $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id, 'name' => 'Original KB']);

    SapiensServer::actingAs($this->user)
        ->tool(UpdateKnowledgeBaseTool::class, [
            'knowledge_base_id' => $kb->id,
            'description' => 'Updated description',
        ])
        ->assertOk()
        ->assertSee('Updated description')
        ->assertSee('Original KB');

    expect($kb->fresh()->description)->toBe('Updated description');
    expect($kb->fresh()->name)->toBe('Original KB'); // untouched
});

it('delete_knowledge_base removes a KB in the caller context', function () {
    $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);

    SapiensServer::actingAs($this->user)
        ->tool(DeleteKnowledgeBaseTool::class, ['knowledge_base_id' => $kb->id])
        ->assertOk()
        ->assertSee('deleted');

    expect(KnowledgeBase::find($kb->id))->toBeNull();
});

it('get_knowledge_base errors for a KB outside the caller context', function () {
    $other = KnowledgeBase::factory()->create(); // a different account's KB

    SapiensServer::actingAs($this->user)
        ->tool(GetKnowledgeBaseTool::class, ['knowledge_base_id' => $other->id])
        ->assertHasErrors();
});
