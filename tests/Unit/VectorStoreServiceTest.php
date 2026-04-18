<?php

use App\Enums\Visibility;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Models\Organization;
use App\Models\User;
use App\Services\VectorStoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(VectorStoreService::class);
});

function makeKbForTest(): KnowledgeBase
{
    $org = Organization::create(['name' => 'VS', 'slug' => 'vs-'.uniqid()]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    return KnowledgeBase::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
    ]);
}

function makeDocumentForKb(KnowledgeBase $kb): Document
{
    return Document::create([
        'user_id' => $kb->user_id,
        'organization_id' => $kb->organization_id,
        'name' => 'D',
        'original_filename' => 'd.txt',
        'type' => 'txt',
        'file_size' => 1,
        'visibility' => Visibility::Organization,
    ]);
}

test('insertChunks writes rows to the KB default connection', function () {
    $kb = makeKbForTest();
    $doc = makeDocumentForKb($kb);

    $this->service->insertChunks(
        $kb,
        [
            ['content' => 'a', 'index' => 0, 'metadata' => ['p' => 1], 'embedding' => [0.1, 0.2]],
            ['content' => 'b', 'index' => 1, 'metadata' => null, 'embedding' => null],
        ],
        'text-embedding-3-small',
        documentId: $doc->id,
    );

    $rows = KnowledgeBaseChunk::where('knowledge_base_id', $kb->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('chunk_index', 0)->content)->toBe('a')
        ->and($rows->firstWhere('chunk_index', 0)->document_id)->toBe($doc->id)
        ->and($rows->firstWhere('chunk_index', 0)->embedding_model)->toBe('text-embedding-3-small');
});

test('insertChunks is a no-op on empty input', function () {
    $kb = makeKbForTest();

    $this->service->insertChunks($kb, [], 'model');

    expect(KnowledgeBaseChunk::count())->toBe(0);
});

test('insertChunks serializes array embeddings as pgvector strings', function () {
    $kb = makeKbForTest();
    $doc = makeDocumentForKb($kb);

    $this->service->insertChunks(
        $kb,
        [['content' => 'x', 'index' => 0, 'metadata' => null, 'embedding' => [0.1, 0.2, 0.3]]],
        'm',
        documentId: $doc->id,
    );

    // Read the raw column (bypass the Pgvector cast) to assert the wire format.
    $raw = DB::table('knowledge_base_chunks')->where('knowledge_base_id', $kb->id)->value('embedding');
    expect($raw)->toBe('[0.1,0.2,0.3]');
});

test('deleteForDocumentInKnowledgeBase removes only matching rows', function () {
    $kb = makeKbForTest();
    $docA = makeDocumentForKb($kb);
    $docB = makeDocumentForKb($kb);

    $this->service->insertChunks(
        $kb,
        [['content' => 'a', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        documentId: $docA->id,
    );
    $this->service->insertChunks(
        $kb,
        [['content' => 'b', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        documentId: $docB->id,
    );

    $deleted = $this->service->deleteForDocumentInKnowledgeBase($kb, $docA->id);

    expect($deleted)->toBe(1)
        ->and(KnowledgeBaseChunk::count())->toBe(1)
        ->and(KnowledgeBaseChunk::first()->document_id)->toBe($docB->id);
});

test('deleteForKnowledgeBaseDocument removes only rows with matching kb_document_id', function () {
    // FKs on knowledge_base_chunks were dropped in F5 — chunks can carry any
    // knowledge_base_document_id string since the constraint is gone.
    $kb = makeKbForTest();

    $this->service->insertChunks(
        $kb,
        [['content' => 'a', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        knowledgeBaseDocumentId: 'kbdoc_01',
    );
    $this->service->insertChunks(
        $kb,
        [['content' => 'b', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        knowledgeBaseDocumentId: 'kbdoc_02',
    );

    $deleted = $this->service->deleteForKnowledgeBaseDocument($kb, 'kbdoc_01');

    expect($deleted)->toBe(1)
        ->and(KnowledgeBaseChunk::count())->toBe(1);
});

test('chunks can be inserted without existing FK rows (cross-DB safe)', function () {
    // After F5, knowledge_base_id, document_id, and knowledge_base_document_id
    // are plain indexed columns with no FK. A tenant-PG chunk row may reference
    // app-DB ids that the chunk's connection cannot see, so the schema must
    // tolerate "orphaned" references.
    $kb = makeKbForTest();

    $this->service->insertChunks(
        $kb,
        [['content' => 'orphan', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        documentId: 'doc_does_not_exist_in_app_db',
        knowledgeBaseDocumentId: 'kbdoc_does_not_exist_either',
    );

    expect(KnowledgeBaseChunk::where('knowledge_base_id', $kb->id)->count())->toBe(1);
});

test('deleteAllForKnowledgeBase removes every chunk of the KB', function () {
    $kb = makeKbForTest();
    $doc = makeDocumentForKb($kb);

    $this->service->insertChunks(
        $kb,
        [
            ['content' => 'a', 'index' => 0, 'metadata' => null, 'embedding' => null],
            ['content' => 'b', 'index' => 1, 'metadata' => null, 'embedding' => null],
            ['content' => 'c', 'index' => 2, 'metadata' => null, 'embedding' => null],
        ],
        'm',
        documentId: $doc->id,
    );

    $deleted = $this->service->deleteAllForKnowledgeBase($kb);

    expect($deleted)->toBe(3)
        ->and(KnowledgeBaseChunk::where('knowledge_base_id', $kb->id)->count())->toBe(0);
});

test('deleteAllForDocument removes chunks across every KB the document belongs to', function () {
    $kbA = makeKbForTest();
    $kbB = KnowledgeBase::factory()->create([
        'user_id' => $kbA->user_id,
        'organization_id' => $kbA->organization_id,
        'visibility' => Visibility::Organization,
    ]);
    $doc = makeDocumentForKb($kbA);

    $this->service->insertChunks(
        $kbA,
        [['content' => 'a', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        documentId: $doc->id,
    );
    $this->service->insertChunks(
        $kbB,
        [['content' => 'b', 'index' => 0, 'metadata' => null, 'embedding' => null]],
        'm',
        documentId: $doc->id,
    );

    expect(KnowledgeBaseChunk::where('document_id', $doc->id)->count())->toBe(2);

    $deleted = $this->service->deleteAllForDocument($doc);

    expect($deleted)->toBe(2)
        ->and(KnowledgeBaseChunk::where('document_id', $doc->id)->count())->toBe(0);
});

test('chunkCount reads from the same connection chunks were written to', function () {
    $kb = makeKbForTest();
    $doc = makeDocumentForKb($kb);

    $this->service->insertChunks(
        $kb,
        [
            ['content' => 'a', 'index' => 0, 'metadata' => null, 'embedding' => null],
            ['content' => 'b', 'index' => 1, 'metadata' => null, 'embedding' => null],
        ],
        'm',
        documentId: $doc->id,
    );

    expect($this->service->chunkCount($kb))->toBe(2);
});

test('delete helpers return zero when no rows match', function () {
    $kb = makeKbForTest();

    expect($this->service->deleteForDocumentInKnowledgeBase($kb, 'doc_missing'))->toBe(0)
        ->and($this->service->deleteForKnowledgeBaseDocument($kb, 'kbdoc_missing'))->toBe(0)
        ->and($this->service->deleteAllForKnowledgeBase($kb))->toBe(0);
});

test('searchSimilar returns empty when the KB list is empty', function () {
    expect($this->service->searchSimilar([], [0.1, 0.2], 5, 0.7))->toBeEmpty();
});

test('searchSimilar returns empty when the query embedding is empty', function () {
    $kb = makeKbForTest();
    expect($this->service->searchSimilar([$kb->id], [], 5, 0.7))->toBeEmpty();
});

test('searchSimilar on non-pgsql returns chunks scoped to the given KBs with placeholder distance', function () {
    $kbA = makeKbForTest();
    $kbB = makeKbForTest();
    $docA = makeDocumentForKb($kbA);
    $docB = makeDocumentForKb($kbB);

    // Write chunks to two different KBs — the sqlite fallback can't compare
    // vector distance, but it should still scope by KB id.
    $this->service->insertChunks(
        $kbA,
        [
            ['content' => 'a1', 'index' => 0, 'metadata' => null, 'embedding' => [0.1, 0.2]],
            ['content' => 'a2', 'index' => 1, 'metadata' => null, 'embedding' => [0.3, 0.4]],
        ],
        'm',
        documentId: $docA->id,
    );
    $this->service->insertChunks(
        $kbB,
        [['content' => 'b1', 'index' => 0, 'metadata' => null, 'embedding' => [0.5, 0.6]]],
        'm',
        documentId: $docB->id,
    );

    $results = $this->service->searchSimilar([$kbA->id], [0.1, 0.2], 10, 0.7);

    expect($results)->toHaveCount(2)
        ->and($results->pluck('knowledge_base_id')->unique()->values()->all())->toBe([$kbA->id]);

    foreach ($results as $row) {
        expect((float) $row->distance)->toBe(0.0);
    }
});

test('searchSimilar respects the topK limit', function () {
    $kb = makeKbForTest();
    $doc = makeDocumentForKb($kb);

    $chunks = [];
    for ($i = 0; $i < 6; $i++) {
        $chunks[] = ['content' => "c{$i}", 'index' => $i, 'metadata' => null, 'embedding' => [0.1]];
    }
    $this->service->insertChunks($kb, $chunks, 'm', documentId: $doc->id);

    $results = $this->service->searchSimilar([$kb->id], [0.1], 3, 0.7);

    expect($results)->toHaveCount(3);
});

test('searchSimilar skips chunks without an embedding', function () {
    $kb = makeKbForTest();
    $doc = makeDocumentForKb($kb);

    $this->service->insertChunks(
        $kb,
        [
            ['content' => 'with', 'index' => 0, 'metadata' => null, 'embedding' => [0.1]],
            ['content' => 'without', 'index' => 1, 'metadata' => null, 'embedding' => null],
        ],
        'm',
        documentId: $doc->id,
    );

    $results = $this->service->searchSimilar([$kb->id], [0.1], 10, 0.7);

    expect($results)->toHaveCount(1)
        ->and($results->first()->content)->toBe('with');
});

test('searchSimilar silently drops KB ids that no longer exist', function () {
    $results = $this->service->searchSimilar(['kb_ghost_id'], [0.1], 5, 0.7);
    expect($results)->toBeEmpty();
});
