<?php

use App\Models\App;
use App\Models\Record;
use App\Models\User;
use App\Services\Records\AppDataOverview;
use App\Services\Records\RecordQueryService;

/**
 * Data-exposure surface: the shared AppDataOverview digest (big picture — objects,
 * live counts, relation graph), the granular query power on RecordQueryService
 * (total/has_more paging, grouped aggregation with date buckets), and the phase-2
 * power (relation traversal in filters + cross-field text search). Both the MCP
 * tools and the in-app agent tools route through this engine.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->salesApp = App::factory()->create(['user_id' => $this->user->id, 'organization_id' => null]);
});

function overviewManifest(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_overview',
        'slug' => 'sales',
        'name' => 'Sales',
        'version' => 1,
        'objects' => [
            [
                'id' => 'obj_orders',
                'slug' => 'orders',
                'name' => 'Order',
                'fields' => [
                    ['id' => 'fld_name', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                    ['id' => 'fld_amount', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'currency'],
                    ['id' => 'fld_status', 'slug' => 'status', 'name' => 'Status', 'type' => 'single_select'],
                    ['id' => 'fld_date', 'slug' => 'order_date', 'name' => 'Order date', 'type' => 'date'],
                    ['id' => 'fld_customer', 'slug' => 'customer', 'name' => 'Customer', 'type' => 'relation', 'target_object_id' => 'obj_customers', 'cardinality' => 'many_to_one', 'inverse_field_id' => 'fld_orders'],
                ],
            ],
            [
                'id' => 'obj_customers',
                'slug' => 'customers',
                'name' => 'Customer',
                'fields' => [
                    ['id' => 'fld_cname', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
                    ['id' => 'fld_orders', 'slug' => 'orders', 'name' => 'Orders', 'type' => 'relation', 'target_object_id' => 'obj_orders', 'cardinality' => 'one_to_many', 'inverse_field_id' => 'fld_customer'],
                ],
            ],
        ],
        'pages' => [],
        'workflows' => [
            ['id' => 'wf_welcome', 'slug' => 'welcome', 'name' => 'Welcome', 'trigger' => ['type' => 'record.created', 'object_id' => 'obj_customers']],
        ],
    ];
}

/**
 * Seed two customers and three orders linked to them. Returns the customer ids.
 *
 * @return array{acme: string, beta: string}
 */
function seedSales(App $app): array
{
    $acme = Record::create(['app_id' => $app->id, 'object_definition_id' => 'obj_customers', 'organization_id' => null, 'data' => ['name' => 'Acme']]);
    $beta = Record::create(['app_id' => $app->id, 'object_definition_id' => 'obj_customers', 'organization_id' => null, 'data' => ['name' => 'Beta']]);

    $orders = [
        ['name' => 'Apple', 'amount' => 100, 'status' => 'open', 'order_date' => '2026-01-15', 'customer' => $acme->id],
        ['name' => 'Banana', 'amount' => 200, 'status' => 'open', 'order_date' => '2026-01-22', 'customer' => $acme->id],
        ['name' => 'Cherry', 'amount' => 500, 'status' => 'won', 'order_date' => '2026-02-03', 'customer' => $beta->id],
    ];
    foreach ($orders as $data) {
        Record::create(['app_id' => $app->id, 'object_definition_id' => 'obj_orders', 'organization_id' => null, 'data' => $data]);
    }

    return ['acme' => $acme->id, 'beta' => $beta->id];
}

it('builds the full digest with live counts and a relation graph', function () {
    seedSales($this->salesApp);

    $digest = app(AppDataOverview::class)->full($this->salesApp, overviewManifest());

    expect($digest['record_counts']['obj_orders'])->toBe(3)
        ->and($digest['record_counts']['obj_customers'])->toBe(2)
        ->and($digest['workflows_by_object']['obj_customers'][0]['id'])->toBe('wf_welcome');

    $edge = collect($digest['relations'])->firstWhere('field_id', 'fld_customer');
    expect($edge['from_object_id'])->toBe('obj_orders')
        ->and($edge['to_object_id'])->toBe('obj_customers')
        ->and($edge['kind'])->toBe('belongs_to');
});

it('projects a compact digest with record_count and derived/relation field metadata', function () {
    seedSales($this->salesApp);

    $digest = app(AppDataOverview::class)->compact($this->salesApp, overviewManifest());
    $orders = collect($digest['objects'])->firstWhere('id', 'obj_orders');

    expect($orders['record_count'])->toBe(3);

    $relField = collect($orders['fields'])->firstWhere('id', 'fld_customer');
    expect($relField['target_object_id'])->toBe('obj_customers')
        ->and($relField['cardinality'])->toBe('many_to_one')
        ->and($relField['derived'])->toBeFalse();
});

it('restricts the compact digest to the given object ids', function () {
    $digest = app(AppDataOverview::class)->compact($this->salesApp, overviewManifest(), ['obj_orders']);

    expect($digest['objects'])->toHaveCount(1)
        ->and($digest['objects'][0]['id'])->toBe('obj_orders');
});

it('aggregates a numeric metric grouped by a field', function () {
    seedSales($this->salesApp);

    $groups = app(RecordQueryService::class)->groupedAggregate(
        $this->salesApp,
        ['object_id' => 'obj_orders'],
        'sum',
        'fld_amount',
        'fld_status',
        null,
        overviewManifest(),
    );

    $byGroup = collect($groups)->pluck('value', 'group');
    expect((float) $byGroup['open'])->toBe(300.0)
        ->and((float) $byGroup['won'])->toBe(500.0);
});

it('buckets a date group field by month', function () {
    seedSales($this->salesApp);

    $groups = app(RecordQueryService::class)->groupedAggregate(
        $this->salesApp,
        ['object_id' => 'obj_orders'],
        'count',
        null,
        'fld_date',
        'month',
        overviewManifest(),
    );

    $byMonth = collect($groups)->mapWithKeys(fn ($g) => [substr((string) $g['group'], 0, 7) => $g['value']]);
    expect($byMonth['2026-01'])->toBe(2)
        ->and($byMonth['2026-02'])->toBe(1);
});

it('returns total and has_more for paged queries', function () {
    seedSales($this->salesApp);

    $result = app(RecordQueryService::class)->queryWithMeta(
        $this->salesApp,
        ['object_id' => 'obj_orders', 'limit' => 2],
        overviewManifest(),
    );

    expect($result['records']->count())->toBe(2)
        ->and($result['total'])->toBe(3)
        ->and($result['has_more'])->toBeTrue();

    $last = app(RecordQueryService::class)->queryWithMeta(
        $this->salesApp,
        ['object_id' => 'obj_orders', 'limit' => 2, 'offset' => 2],
        overviewManifest(),
    );

    expect($last['records']->count())->toBe(1)
        ->and($last['has_more'])->toBeFalse();
});

it('traverses a belongs_to relation in a filter', function () {
    seedSales($this->salesApp);

    // Orders whose customer.name = "Acme" → Apple + Banana.
    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        [
            'object_id' => 'obj_orders',
            'filter' => ['op' => 'related', 'field_id' => 'fld_customer', 'condition' => ['op' => 'eq', 'field_id' => 'fld_cname', 'value' => 'Acme']],
        ],
        overviewManifest(),
    );

    expect($records->pluck('data')->pluck('name')->sort()->values()->all())->toBe(['Apple', 'Banana']);
});

it('traverses a has_many relation in a filter', function () {
    seedSales($this->salesApp);

    // Customers that have an order with amount >= 500 → Beta only.
    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        [
            'object_id' => 'obj_customers',
            'filter' => ['op' => 'related', 'field_id' => 'fld_orders', 'condition' => ['op' => 'gte', 'field_id' => 'fld_amount', 'value' => 500]],
        ],
        overviewManifest(),
    );

    expect($records->pluck('data')->pluck('name')->all())->toBe(['Beta']);
});

it('negates a relation filter with not', function () {
    seedSales($this->salesApp);

    // Customers that do NOT have an order >= 500 → Acme only.
    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        [
            'object_id' => 'obj_customers',
            'filter' => ['op' => 'not', 'condition' => ['op' => 'related', 'field_id' => 'fld_orders', 'condition' => ['op' => 'gte', 'field_id' => 'fld_amount', 'value' => 500]]],
        ],
        overviewManifest(),
    );

    expect($records->pluck('data')->pluck('name')->all())->toBe(['Acme']);
});

it('runs a cross-field text search', function () {
    seedSales($this->salesApp);

    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        ['object_id' => 'obj_orders', 'search' => 'ana'],
        overviewManifest(),
    );

    expect($records->pluck('data')->pluck('name')->all())->toBe(['Banana']);
});

it('expands a belongs_to relation inline', function () {
    $ids = seedSales($this->salesApp);

    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        ['object_id' => 'obj_orders', 'sort' => [['field_id' => 'fld_amount', 'direction' => 'asc']], 'expand' => ['fld_customer']],
        overviewManifest(),
    );

    // Apple + Banana belong to Acme, Cherry to Beta.
    $apple = $records->firstWhere('data.name', 'Apple');
    $cherry = $records->firstWhere('data.name', 'Cherry');

    expect($apple->expanded['fld_customer']['id'])->toBe($ids['acme'])
        ->and($apple->expanded['fld_customer']['data']['name'])->toBe('Acme')
        ->and($cherry->expanded['fld_customer']['data']['name'])->toBe('Beta');
});

it('expands to null when the relation is unset', function () {
    Record::create(['app_id' => $this->salesApp->id, 'object_definition_id' => 'obj_orders', 'organization_id' => null, 'data' => ['name' => 'Orphan', 'amount' => 10]]);

    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        ['object_id' => 'obj_orders', 'expand' => ['fld_customer']],
        overviewManifest(),
    );

    expect($records->first()->expanded['fld_customer'])->toBeNull();
});

it('does not expand a has_many relation inline (null)', function () {
    $ids = seedSales($this->salesApp);

    $records = app(RecordQueryService::class)->query(
        $this->salesApp,
        ['object_id' => 'obj_customers', 'expand' => ['fld_orders']],
        overviewManifest(),
    );

    expect($records->first()->expanded['fld_orders'])->toBeNull();
});

it('counts respect search and relation filters', function () {
    seedSales($this->salesApp);

    $total = app(RecordQueryService::class)->count(
        $this->salesApp,
        [
            'object_id' => 'obj_orders',
            'filter' => ['op' => 'related', 'field_id' => 'fld_customer', 'condition' => ['op' => 'eq', 'field_id' => 'fld_cname', 'value' => 'Acme']],
        ],
        overviewManifest(),
    );

    expect($total)->toBe(2);
});
