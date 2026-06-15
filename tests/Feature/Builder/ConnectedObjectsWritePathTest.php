<?php

use App\Models\Integration;
use App\Models\Record;
use App\Models\User;
use App\Services\Connected\ConnectedObjectWriter;
use Illuminate\Support\Facades\Http;

/**
 * Builder power #2, write-path slice — a create/update from the app UI reaches the
 * external system through the integration (the logged-in user is the actor →
 * direct write), with manifest values mapped back to the external body via
 * field_map, storing nothing locally (passthrough). An object with no create/
 * update operation is read-only. See docs/app-builder-connected-objects-contract.md §6.4.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);
});

function writableDealObject(string $integrationId, bool $writable = true): array
{
    $operations = [
        'list' => ['method' => 'GET', 'path' => '/crm/v3/objects/deals', 'collection_path' => 'results'],
    ];
    if ($writable) {
        $operations['create'] = ['method' => 'POST', 'path' => '/crm/v3/objects/deals'];
        $operations['update'] = ['method' => 'PATCH', 'path' => '/crm/v3/objects/deals/{id}'];
    }

    return [
        'id' => 'obj_dealobject',
        'slug' => 'deals',
        'name' => 'Deal',
        'fields' => [
            ['id' => 'fld_namefield', 'slug' => 'name', 'name' => 'Name', 'type' => 'string'],
            ['id' => 'fld_amountfield', 'slug' => 'amount', 'name' => 'Amount', 'type' => 'number'],
            ['id' => 'fld_ownerfield', 'slug' => 'owner', 'name' => 'Owner', 'type' => 'string'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => $integrationId,
            'id_path' => 'id',
            'operations' => $operations,
            'field_map' => [
                ['field_id' => 'fld_namefield', 'external_path' => 'properties.dealname'],
                ['field_id' => 'fld_amountfield', 'external_path' => 'properties.amount'],
                ['field_id' => 'fld_ownerfield', 'external_path' => 'properties.owner', 'readonly' => true],
            ],
        ],
    ];
}

it('creates an external record with mapped body and auth, storing nothing', function () {
    Http::fake(['api.example.com/*' => Http::response(['id' => 'd99'], 201)]);

    $result = app(ConnectedObjectWriter::class)->create(
        writableDealObject($this->integration->id),
        $this->integration,
        ['name' => 'Acme', 'amount' => 1000, 'owner' => 'ignored'],
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['id'])->toBe('d99');

    expect(Record::count())->toBe(0);

    Http::assertSent(function ($req) {
        $body = $req->data();

        return $req->method() === 'POST'
            && str_contains($req->url(), 'api.example.com/crm/v3/objects/deals')
            && $req->hasHeader('Authorization', 'Bearer TKN')
            && $body['properties']['dealname'] === 'Acme'
            && $body['properties']['amount'] === 1000
            // readonly-mapped field is never written back
            && ! isset($body['properties']['owner']);
    });
});

it('updates an external record by templating {id} into the path (partial body)', function () {
    Http::fake(['api.example.com/*' => Http::response(['id' => 'd1'], 200)]);

    $result = app(ConnectedObjectWriter::class)->update(
        writableDealObject($this->integration->id),
        $this->integration,
        'd1',
        ['name' => 'Renamed'],
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['id'])->toBe('d1');

    Http::assertSent(function ($req) {
        $body = $req->data();

        return $req->method() === 'PATCH'
            && str_contains($req->url(), 'api.example.com/crm/v3/objects/deals/d1')
            && $body['properties']['dealname'] === 'Renamed'
            // partial update: amount was not sent, so it is absent
            && ! isset($body['properties']['amount']);
    });
});

it('degrades to an error when the object is read-only (no write operation)', function () {
    $result = app(ConnectedObjectWriter::class)->create(
        writableDealObject($this->integration->id, writable: false),
        $this->integration,
        ['name' => 'Acme'],
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('read-only');

    Http::assertNothingSent();
});

it('degrades to an error result when the external system rejects the write', function () {
    Http::fake(['api.example.com/*' => Http::response('nope', 422)]);

    $result = app(ConnectedObjectWriter::class)->create(
        writableDealObject($this->integration->id),
        $this->integration,
        ['name' => 'Acme'],
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('422');
});
