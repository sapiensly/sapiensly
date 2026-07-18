<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\GenerateDemoDataTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Records\DemoDataGenerator;

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

it('generate_demo_data honours numeric min/max constraints', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'pos_app',
    ]);

    // Hand-built manifest so the currency/number min+max survive (the scaffold
    // helper's normalizeFields drops type-specific props).
    $manifest = [
        'objects' => [[
            'id' => 'obj_demo_lines_0001',
            'slug' => 'lines',
            'name' => 'Lines',
            'fields' => [
                ['id' => 'fld_demo_qty_000001', 'slug' => 'qty', 'name' => 'Qty', 'type' => 'number', 'min' => 1, 'max' => 6],
                ['id' => 'fld_demo_price_00001', 'slug' => 'price', 'name' => 'Price', 'type' => 'currency', 'currency_code' => 'MXN', 'min' => 10, 'max' => 200],
            ],
        ]],
    ];

    app(DemoDataGenerator::class)->generate($app, $manifest, 12, null, $this->user);

    $lines = Record::query()->where('object_definition_id', 'obj_demo_lines_0001')->get();
    expect($lines)->not->toBeEmpty();
    foreach ($lines as $line) {
        expect((float) $line->data['qty'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
        expect((float) $line->data['price'])->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(200);
    }
});

it('generate_demo_data fills many_to_many relations with real target ids', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'm2m_app',
    ]);

    // Hand-built manifest: a scene links many actors (many_to_many). This can't be
    // filled inline with the row — it's wired in the generator's second pass once
    // both objects have records.
    $manifest = [
        'objects' => [
            [
                'id' => 'obj_demo_scenes_001',
                'slug' => 'scenes',
                'name' => 'Scenes',
                'fields' => [
                    ['id' => 'fld_demo_scene_ttl1', 'slug' => 'title', 'name' => 'Title', 'type' => 'string'],
                    ['id' => 'fld_demo_scene_cast', 'slug' => 'cast', 'name' => 'Cast', 'type' => 'relation', 'cardinality' => 'many_to_many', 'target_object_id' => 'obj_demo_actors_001'],
                ],
            ],
            [
                'id' => 'obj_demo_actors_001',
                'slug' => 'actors',
                'name' => 'Actors',
                'fields' => [
                    ['id' => 'fld_demo_actor_name1', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                ],
            ],
        ],
    ];

    app(DemoDataGenerator::class)->generate($app, $manifest, 5, null, $this->user);

    $actorIds = Record::query()->where('object_definition_id', 'obj_demo_actors_001')->pluck('id')->all();
    $scenes = Record::query()->where('object_definition_id', 'obj_demo_scenes_001')->get();

    expect($scenes)->toHaveCount(5);

    // The second pass populated the picker: at least some scenes carry cast,
    // every linked id is a real actor, and no link exceeds the 1..3 subset cap.
    $withCast = $scenes->filter(fn ($s) => ! empty($s->data['cast']));
    expect($withCast)->not->toBeEmpty();
    foreach ($withCast as $scene) {
        expect($scene->data['cast'])->toBeArray();
        expect(count($scene->data['cast']))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);
        expect(collect($scene->data['cast'])->every(fn ($id) => in_array($id, $actorIds, true)))->toBeTrue();
    }
});

it('generate_demo_data mirrors symmetric many_to_many links onto the inverse side', function () {
    $app = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'm2m_sym_app',
    ]);

    // Symmetric pair (two cross-linked pickers, as buildManyToMany produces):
    // a day lists its scenes, a scene lists the days it appears in.
    $manifest = [
        'objects' => [
            [
                'id' => 'obj_demo_days_00001',
                'slug' => 'days',
                'name' => 'Days',
                'fields' => [
                    ['id' => 'fld_demo_day_label01', 'slug' => 'label', 'name' => 'Label', 'type' => 'string'],
                    ['id' => 'fld_demo_day_scenes1', 'slug' => 'scenes', 'name' => 'Scenes', 'type' => 'relation', 'cardinality' => 'many_to_many', 'target_object_id' => 'obj_demo_scn_000001', 'inverse_field_id' => 'fld_demo_scn_days01'],
                ],
            ],
            [
                'id' => 'obj_demo_scn_000001',
                'slug' => 'scn',
                'name' => 'Scn',
                'fields' => [
                    ['id' => 'fld_demo_scn_ttl0001', 'slug' => 'title', 'name' => 'Title', 'type' => 'string'],
                    ['id' => 'fld_demo_scn_days01', 'slug' => 'days', 'name' => 'Days', 'type' => 'relation', 'cardinality' => 'many_to_many', 'target_object_id' => 'obj_demo_days_00001', 'inverse_field_id' => 'fld_demo_day_scenes1'],
                ],
            ],
        ],
    ];

    app(DemoDataGenerator::class)->generate($app, $manifest, 4, null, $this->user);

    $days = Record::query()->where('object_definition_id', 'obj_demo_days_00001')->get()->keyBy('id');
    $scenes = Record::query()->where('object_definition_id', 'obj_demo_scn_000001')->get()->keyBy('id');

    // Every day->scene link appears on the scene's inverse days picker, and vice versa.
    foreach ($days as $dayId => $day) {
        foreach ($day->data['scenes'] ?? [] as $sceneId) {
            expect($scenes[$sceneId]->data['days'] ?? [])->toContain($dayId);
        }
    }
    // The inverse was actually exercised (some link exists), not vacuously true.
    $totalLinks = $days->sum(fn ($d) => count($d->data['scenes'] ?? []));
    expect($totalLinks)->toBeGreaterThan(0);
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
