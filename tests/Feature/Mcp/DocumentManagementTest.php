<?php

use App\Enums\DocumentType;
use App\Enums\Visibility;
use App\Jobs\ProcessDocumentForKnowledgeBase;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\AddDocumentTool;
use App\Mcp\Tools\Data\DeleteDocumentTool;
use App\Mcp\Tools\Data\GetDocumentTool;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * Create an inline document owned by the test user.
 */
function inlineDocument(User $user, string $name = 'Note', string $body = 'hello world'): Document
{
    return Document::create([
        'user_id' => $user->id,
        'organization_id' => $user->organization_id,
        'name' => $name,
        'type' => DocumentType::Txt,
        'body' => $body,
        'visibility' => Visibility::Private,
    ]);
}

it('add_document creates an inline document from raw text', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AddDocumentTool::class, [
            'name' => 'Refund policy',
            'body' => 'Refunds are issued within 30 days.',
            'type' => 'md',
        ])
        ->assertOk()
        ->assertSee('Refund policy')
        ->assertSee('Refunds are issued within 30 days.');

    $doc = Document::where('user_id', $this->user->id)->where('name', 'Refund policy')->first();
    expect($doc)->not->toBeNull();
    expect($doc->isInline())->toBeTrue();
});

it('add_document attaches to a KB and queues embedding', function () {
    Queue::fake();
    $kb = KnowledgeBase::factory()->create(['user_id' => $this->user->id]);

    SapiensServer::actingAs($this->user)
        ->tool(AddDocumentTool::class, [
            'name' => 'FAQ',
            'body' => 'Q: hours? A: 9-5.',
            'knowledge_base_id' => $kb->id,
        ])
        ->assertOk()
        ->assertSee($kb->id);

    $doc = Document::where('user_id', $this->user->id)->where('name', 'FAQ')->first();
    expect($doc->knowledgeBases()->where('knowledge_base_id', $kb->id)->exists())->toBeTrue();
    Queue::assertPushed(ProcessDocumentForKnowledgeBase::class);
});

it('add_document rejects a KB outside the caller context', function () {
    $other = KnowledgeBase::factory()->create(); // a different account's KB

    SapiensServer::actingAs($this->user)
        ->tool(AddDocumentTool::class, [
            'name' => 'Leak',
            'body' => 'x',
            'knowledge_base_id' => $other->id,
        ])
        ->assertHasErrors();

    expect(Document::where('name', 'Leak')->exists())->toBeFalse();
});

it('get_document returns the body for an inline document', function () {
    $doc = inlineDocument($this->user, 'Readme', 'inline body text');

    SapiensServer::actingAs($this->user)
        ->tool(GetDocumentTool::class, ['document_id' => $doc->id])
        ->assertOk()
        ->assertSee('inline body text')
        ->assertSee('is_inline');
});

it('delete_document deletes the document entirely', function () {
    $doc = inlineDocument($this->user);

    SapiensServer::actingAs($this->user)
        ->tool(DeleteDocumentTool::class, ['document_id' => $doc->id])
        ->assertOk()
        ->assertSee('deleted');

    expect(Document::find($doc->id))->toBeNull();
});
