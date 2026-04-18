<?php

use App\Services\VectorStoreSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->schema = app(VectorStoreSchema::class);

    // Use a dedicated in-memory connection so the bootstrap does not collide
    // with the application's migrated tables (RefreshDatabase already created
    // knowledge_base_chunks on the default connection).
    config([
        'database.connections.vector_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ],
    ]);

    DB::purge('vector_probe');
    $this->probe = DB::connection('vector_probe');
});

test('ensureSchema creates the chunks table on a fresh connection', function () {
    expect($this->schema->hasSchema($this->probe))->toBeFalse();

    $this->schema->ensureSchema($this->probe);

    expect($this->schema->hasSchema($this->probe))->toBeTrue()
        ->and($this->probe->getSchemaBuilder()->hasColumns(VectorStoreSchema::TABLE, [
            'id', 'knowledge_base_id', 'document_id', 'knowledge_base_document_id',
            'content', 'chunk_index', 'metadata', 'embedding_model', 'embedding',
            'created_at', 'updated_at',
        ]))->toBeTrue();
});

test('ensureSchema is idempotent — calling it twice is safe', function () {
    $this->schema->ensureSchema($this->probe);
    $this->schema->ensureSchema($this->probe);

    expect($this->schema->hasSchema($this->probe))->toBeTrue();
});

test('dropSchema removes the chunks table', function () {
    $this->schema->ensureSchema($this->probe);
    expect($this->schema->hasSchema($this->probe))->toBeTrue();

    $this->schema->dropSchema($this->probe);

    expect($this->schema->hasSchema($this->probe))->toBeFalse();
});

test('dropSchema is idempotent when the table is already gone', function () {
    $this->schema->dropSchema($this->probe);

    expect($this->schema->hasSchema($this->probe))->toBeFalse();
});

test('hasExtension reports true on non-pgsql drivers', function () {
    expect($this->schema->hasExtension($this->probe))->toBeTrue();
});

test('installExtension is a no-op on non-pgsql drivers and reports success', function () {
    $result = $this->schema->installExtension($this->probe);

    expect($result['success'])->toBeTrue()
        ->and($result)->toHaveKey('message');
});

test('the bootstrapped table accepts an insert and round-trips the row', function () {
    $this->schema->ensureSchema($this->probe);

    $this->probe->table(VectorStoreSchema::TABLE)->insert([
        'knowledge_base_id' => 'kb_01',
        'document_id' => 'doc_01',
        'content' => 'hello world',
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = $this->probe->table(VectorStoreSchema::TABLE)->first();

    expect($row->content)->toBe('hello world')
        ->and($row->knowledge_base_id)->toBe('kb_01');
});

test('bootstrapped schema has no foreign key constraints (cross-DB safe)', function () {
    $this->schema->ensureSchema($this->probe);

    // Writing a chunk with non-existent knowledge_base_id must succeed because
    // the foreign key to `knowledge_bases` is intentionally absent: the KB row
    // lives in the application database, not on this connection.
    $this->probe->table(VectorStoreSchema::TABLE)->insert([
        'knowledge_base_id' => 'kb_does_not_exist_anywhere',
        'document_id' => 'also_missing',
        'content' => 'orphaned but accepted',
        'chunk_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->probe->table(VectorStoreSchema::TABLE)->count())->toBe(1);
});
