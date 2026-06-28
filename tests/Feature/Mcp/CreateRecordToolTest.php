<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\CreateRecordTool;
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
        'slug' => 'menu_app',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->appModel), [
        'objects' => [
            ['name' => 'Dishes', 'slug' => 'dishes', 'fields' => [
                ['name' => 'Name', 'slug' => 'name', 'type' => 'string', 'options' => null],
                ['name' => 'Price', 'slug' => 'price', 'type' => 'currency', 'options' => null],
            ]],
        ],
        'links' => [],
    ]);
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
    $this->manifest = $manifest;
    $this->dishes = collect($manifest['objects'])->firstWhere('slug', 'dishes');
});

it('create_record accepts values keyed by field id', function () {
    $nameId = collect($this->dishes['fields'])->firstWhere('slug', 'name')['id'];
    $priceId = collect($this->dishes['fields'])->firstWhere('slug', 'price')['id'];

    SapiensServer::actingAs($this->user)
        ->tool(CreateRecordTool::class, [
            'app_slug' => 'menu_app',
            'object_id' => $this->dishes['id'],
            'values' => [$nameId => 'Taco de pastor', $priceId => 28],
        ])
        ->assertOk();

    $record = Record::query()->where('object_definition_id', $this->dishes['id'])->sole();
    expect($record->data['name'])->toBe('Taco de pastor');
    expect((float) $record->data['price'])->toBe(28.0);
});

it('create_record still accepts values keyed by field slug', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateRecordTool::class, [
            'app_slug' => 'menu_app',
            'object_id' => $this->dishes['id'],
            'values' => ['name' => 'Agua de jamaica', 'price' => 25],
        ])
        ->assertOk();

    expect(Record::query()->where('object_definition_id', $this->dishes['id'])->sole()->data['name'])
        ->toBe('Agua de jamaica');
});

it('create_record surfaces per-field validation errors', function () {
    SapiensServer::actingAs($this->user)
        ->tool(CreateRecordTool::class, [
            'app_slug' => 'menu_app',
            'object_id' => $this->dishes['id'],
            'values' => ['price' => 'not-a-number'],
        ])
        ->assertHasErrors()
        ->assertSee('must be a number');
});
