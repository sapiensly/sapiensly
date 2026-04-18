<?php

use App\Enums\Visibility;
use App\Models\CloudProvider;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeScopeWiper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->wiper = app(KnowledgeScopeWiper::class);
});

function makeOrg(?string $slug = null): Organization
{
    return Organization::create(['name' => 'Org', 'slug' => $slug ?? 'org-'.uniqid()]);
}

function seedKnowledgeForOrg(Organization $org): array
{
    $user = User::factory()->create(['organization_id' => $org->id]);

    $kb = KnowledgeBase::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
    ]);

    $doc = Document::create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Doc',
        'original_filename' => 'doc.txt',
        'type' => 'txt',
        'file_size' => 10,
        'visibility' => Visibility::Organization,
    ]);

    KnowledgeBaseChunk::create([
        'knowledge_base_id' => $kb->id,
        'document_id' => $doc->id,
        'content' => 'chunk',
        'chunk_index' => 0,
    ]);

    return [$user, $kb, $doc];
}

test('countForOrganizations returns zeros for empty input', function () {
    expect($this->wiper->countForOrganizations([]))->toBe([
        'knowledge_bases' => 0,
        'documents' => 0,
        'chunks' => 0,
        'organizations' => 0,
    ]);
});

test('countForOrganizations counts only rows in the given orgs', function () {
    $orgA = makeOrg('a');
    $orgB = makeOrg('b');
    seedKnowledgeForOrg($orgA);
    seedKnowledgeForOrg($orgB);

    $counts = $this->wiper->countForOrganizations([$orgA->id]);

    expect($counts['knowledge_bases'])->toBe(1)
        ->and($counts['documents'])->toBe(1)
        ->and($counts['chunks'])->toBe(1)
        ->and($counts['organizations'])->toBe(1);
});

test('wipeForOrganizations deletes only rows in scope', function () {
    $orgA = makeOrg('a-keep');
    $orgB = makeOrg('b-wipe');
    seedKnowledgeForOrg($orgA);
    seedKnowledgeForOrg($orgB);

    $this->wiper->wipeForOrganizations([$orgB->id], 'test wipe');

    expect(KnowledgeBase::where('organization_id', $orgA->id)->count())->toBe(1)
        ->and(KnowledgeBase::where('organization_id', $orgB->id)->count())->toBe(0)
        ->and(Document::where('organization_id', $orgA->id)->count())->toBe(1)
        ->and(Document::where('organization_id', $orgB->id)->count())->toBe(0)
        ->and(KnowledgeBaseChunk::count())->toBe(1);
});

test('wipeForOrganizations also removes pivot rows', function () {
    $org = makeOrg('pivot');
    [, $kb, $doc] = seedKnowledgeForOrg($org);

    DB::table('document_knowledge_base')->insert([
        'knowledge_base_id' => $kb->id,
        'document_id' => $doc->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('document_knowledge_base')->count())->toBe(1);

    $this->wiper->wipeForOrganizations([$org->id], 'pivot test');

    expect(DB::table('document_knowledge_base')->count())->toBe(0);
});

test('wipeForOrganizations is a no-op when the input list is empty', function () {
    $org = makeOrg('preserved');
    seedKnowledgeForOrg($org);

    $this->wiper->wipeForOrganizations([], 'empty input');

    expect(KnowledgeBase::count())->toBe(1);
});

test('tenant impact is always the single org', function () {
    $org = makeOrg('tenant');

    $ids = $this->wiper->impactedOrganizationIdsForDatabaseScope('tenant', $org);

    expect($ids)->toBe([$org->id]);
});

test('tenant impact with null org returns an empty list', function () {
    $ids = $this->wiper->impactedOrganizationIdsForDatabaseScope('tenant', null);

    expect($ids)->toBe([]);
});

test('global impact includes every org when no tenant has an override', function () {
    $orgA = makeOrg('ga');
    $orgB = makeOrg('gb');

    $ids = $this->wiper->impactedOrganizationIdsForDatabaseScope('global');

    expect($ids)->toContain($orgA->id)
        ->and($ids)->toContain($orgB->id);
});

test('global impact excludes orgs that already have a database override', function () {
    $withOverride = makeOrg('with');
    $withoutOverride = makeOrg('without');

    $user = User::factory()->create(['organization_id' => $withOverride->id]);
    CloudProvider::factory()->postgres()->forOrganization($withOverride, $user)->create();

    $ids = $this->wiper->impactedOrganizationIdsForDatabaseScope('global');

    expect($ids)->not->toContain($withOverride->id)
        ->and($ids)->toContain($withoutOverride->id);
});

test('unknown scope returns an empty list', function () {
    expect($this->wiper->impactedOrganizationIdsForDatabaseScope('bogus'))->toBe([]);
});
