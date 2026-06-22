<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\GenerateDemoDataTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->appModel), [
        'objects' => [
            ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
                ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
                ['name' => 'Status', 'slug' => 'status', 'type' => 'single_select', 'options' => [
                    ['value' => 'backlog', 'label' => 'Backlog'],
                    ['value' => 'ready', 'label' => 'Ready'],
                ]],
            ]],
            ['name' => 'Drafts', 'slug' => 'drafts', 'fields' => [
                ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
            ]],
        ],
        'links' => [['from' => 'drafts', 'to' => 'ideas', 'name' => 'idea']],
    ]);
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
    $this->manifest = $manifest;
});

function objectId(array $manifest, string $slug): string
{
    return collect($manifest['objects'])->firstWhere('slug', $slug)['id'];
}

it('generate_demo_data seeds every object and links relations parent-first', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GenerateDemoDataTool::class, [
            'app_slug' => 'content_engine',
            'count' => 4,
        ])
        ->assertOk()
        ->assertSee('seeded');

    $ideasId = objectId($this->manifest, 'ideas');
    $draftsId = objectId($this->manifest, 'drafts');

    $ideas = Record::query()->where('app_id', $this->appModel->id)->where('object_definition_id', $ideasId)->get();
    $drafts = Record::query()->where('app_id', $this->appModel->id)->where('object_definition_id', $draftsId)->get();

    expect($ideas)->toHaveCount(4);
    expect($drafts)->toHaveCount(4);

    // Status came from the select's options.
    expect($ideas->pluck('data.status')->unique()->every(fn ($s) => in_array($s, ['backlog', 'ready'], true)))->toBeTrue();

    // At least one draft links to a real idea (parents are seeded first).
    $ideaIds = $ideas->pluck('id')->all();
    $linked = $drafts->pluck('data.idea')->filter()->all();
    expect($linked)->not->toBeEmpty();
    expect(collect($linked)->every(fn ($id) => in_array($id, $ideaIds, true)))->toBeTrue();
});

it('generate_demo_data can target a subset of objects', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GenerateDemoDataTool::class, [
            'app_slug' => 'content_engine',
            'count' => 3,
            'objects' => ['ideas'],
        ])
        ->assertOk();

    expect(Record::query()->where('object_definition_id', objectId($this->manifest, 'ideas'))->count())->toBe(3);
    expect(Record::query()->where('object_definition_id', objectId($this->manifest, 'drafts'))->count())->toBe(0);
});

it('generate_demo_data errors on an app with no objects', function () {
    $empty = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'empty_app',
    ]);
    $manifests = app(AppManifestService::class);
    $manifests->createVersion($empty, $manifests->initialManifest($empty), $this->user, 'seed');

    SapiensServer::actingAs($this->user)
        ->tool(GenerateDemoDataTool::class, ['app_slug' => 'empty_app'])
        ->assertHasErrors();
});
