<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Data\AggregateRecordsTool;
use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The MCP analytic path: an external AI client must be able to USE the deeper
 * aggregations (distinct_count, median/p90/p95) and the two-dimension pivot
 * (group_by_2) through aggregate_records without error.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->user = User::factory()->create();
    $this->appModel = App::factory()->create(['user_id' => $this->user->id, 'slug' => 'deals_mcp']);
    $this->objectId = 'obj_'.strtolower((string) Str::ulid());
    $this->amountId = 'fld_'.strtolower((string) Str::ulid());
    $this->stageId = 'fld_'.strtolower((string) Str::ulid());
    $this->regionId = 'fld_'.strtolower((string) Str::ulid());

    app(AppManifestService::class)->createVersion($this->appModel, [
        'schema_version' => '1.0.0',
        'id' => $this->appModel->id,
        'slug' => 'deals_mcp',
        'name' => 'Deals',
        'version' => 1,
        'objects' => [[
            'id' => $this->objectId,
            'slug' => 'deals',
            'name' => 'Deal',
            'fields' => [
                ['id' => $this->amountId, 'slug' => 'amount', 'name' => 'Amount', 'type' => 'currency', 'currency_code' => 'MXN'],
                ['id' => $this->stageId, 'slug' => 'stage', 'name' => 'Stage', 'type' => 'single_select', 'options' => [
                    ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'won', 'label' => 'Won'],
                    ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'lost', 'label' => 'Lost'],
                ]],
                ['id' => $this->regionId, 'slug' => 'region', 'name' => 'Region', 'type' => 'single_select', 'options' => [
                    ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'mx', 'label' => 'MX'],
                    ['id' => 'opt_'.strtolower((string) Str::ulid()), 'value' => 'us', 'label' => 'US'],
                ]],
            ],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_'.strtolower((string) Str::ulid()), 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
    ], $this->user);

    foreach ([[100, 'won', 'mx'], [200, 'won', 'us'], [300, 'lost', 'mx'], [400, 'lost', 'mx']] as [$amount, $stage, $region]) {
        Record::create(['app_id' => $this->appModel->id, 'object_definition_id' => $this->objectId, 'data' => ['amount' => $amount, 'stage' => $stage, 'region' => $region]]);
    }
});

it('aggregate_records computes distinct_count over any field', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AggregateRecordsTool::class, ['app_slug' => 'deals_mcp', 'object_id' => $this->objectId, 'aggregation' => 'distinct_count', 'field_id' => $this->stageId])
        ->assertOk()
        ->assertSee('distinct_count')
        ->assertSee('"value":2'); // won, lost
});

it('aggregate_records computes a percentile over a numeric field', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AggregateRecordsTool::class, ['app_slug' => 'deals_mcp', 'object_id' => $this->objectId, 'aggregation' => 'median', 'field_id' => $this->amountId])
        ->assertOk()
        ->assertSee('median')
        ->assertSee('"value":250'); // median of 100,200,300,400
});

it('aggregate_records pivots across two group dimensions (group_by_2)', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AggregateRecordsTool::class, ['app_slug' => 'deals_mcp', 'object_id' => $this->objectId, 'aggregation' => 'sum', 'field_id' => $this->amountId, 'group_by' => $this->stageId, 'group_by_2' => $this->regionId])
        ->assertOk()
        ->assertSee('group2')      // pivot shape present
        ->assertSee('groups');
});

it('aggregate_records reports a clear error when field_id is missing for a non-count aggregation', function () {
    SapiensServer::actingAs($this->user)
        ->tool(AggregateRecordsTool::class, ['app_slug' => 'deals_mcp', 'object_id' => $this->objectId, 'aggregation' => 'median'])
        ->assertSee('field_id is required');
});
