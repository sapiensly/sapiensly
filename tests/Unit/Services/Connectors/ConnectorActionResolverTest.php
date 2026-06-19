<?php

use App\Enums\ConnectorEffect;
use App\Enums\ToolType;
use App\Models\Tool;
use App\Services\Connectors\ConnectorActionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->resolver = new ConnectorActionResolver;
});

function makeTool(ToolType $type, array $config, array $attributes = []): Tool
{
    return Tool::factory()->create(array_merge([
        'type' => $type,
        'config' => $config,
    ], $attributes));
}

it('derives a read effect for a GET rest api tool', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://api.hubspot.com',
        'method' => 'GET',
        'path' => '/deals/{{deal_id}}',
    ]));

    expect($contract->effect)->toBe(ConnectorEffect::Read);
    expect($contract->effectInferred)->toBeTrue();
});

it('derives a write effect for a non-GET rest api tool', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://slack.com',
        'method' => 'POST',
        'path' => '/messages',
    ]));

    expect($contract->effect)->toBe(ConnectorEffect::Write);
    expect($contract->effectInferred)->toBeTrue();
});

it('lets an author effect override win over derivation', function () {
    // POST would derive WRITE, but the author pinned it as a read (e.g. a search).
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://api.example.com',
        'method' => 'POST',
        'path' => '/search',
    ], ['effect' => ConnectorEffect::Read]));

    expect($contract->effect)->toBe(ConnectorEffect::Read);
    expect($contract->effectInferred)->toBeFalse();
});

it('derives read for a graphql query and write for a mutation', function () {
    $read = $this->resolver->resolve(makeTool(ToolType::Graphql, [
        'endpoint' => 'https://api.example.com/graphql',
        'operation_type' => 'query',
        'operation' => 'query Get($id: ID!) { item(id: $id) { id } }',
    ]));
    $write = $this->resolver->resolve(makeTool(ToolType::Graphql, [
        'endpoint' => 'https://api.example.com/graphql',
        'operation_type' => 'mutation',
        'operation' => 'mutation Set($id: ID!) { update(id: $id) { id } }',
    ]));

    expect($read->effect)->toBe(ConnectorEffect::Read);
    expect($write->effect)->toBe(ConnectorEffect::Write);
});

it('derives effect from the database read_only flag', function () {
    $read = $this->resolver->resolve(makeTool(ToolType::Database, [
        'database' => 'analytics',
        'query_template' => 'SELECT * FROM orders WHERE id = :id',
        'read_only' => true,
    ]));
    $write = $this->resolver->resolve(makeTool(ToolType::Database, [
        'database' => 'analytics',
        'query_template' => 'UPDATE orders SET paid = true WHERE id = :id',
        'read_only' => false,
    ]));

    expect($read->effect)->toBe(ConnectorEffect::Read);
    expect($write->effect)->toBe(ConnectorEffect::Write);
});

it('defaults an mcp tool to a gated write effect', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::Mcp, [
        'endpoint' => 'https://mcp.example.com',
    ]));

    expect($contract->effect)->toBe(ConnectorEffect::Write);
    expect($contract->effectInferred)->toBeTrue();
    expect($contract->typed)->toBeFalse();
});

it('lifts typed inputs from a function tool parameters schema', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::Function, [
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string'],
                'amount' => ['type' => 'number'],
            ],
            'required' => ['order_id'],
        ],
    ]));

    expect($contract->typed)->toBeTrue();
    expect($contract->effect)->toBe(ConnectorEffect::Read);
    expect($contract->inputs)->toEqual([
        ['name' => 'order_id', 'type' => 'string', 'required' => true],
        ['name' => 'amount', 'type' => 'number', 'required' => false],
    ]);
});

it('infers rest api inputs from path and body placeholders', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://api.example.com',
        'method' => 'POST',
        'path' => '/deals/{{deal_id}}',
        'request_body_template' => '{"note": "{{note}}"}',
    ]));

    expect($contract->typed)->toBeFalse();
    expect(collect($contract->inputs)->pluck('name')->all())
        ->toEqualCanonicalizing(['deal_id', 'note']);
    expect($contract->inputs)->each->toMatchArray(['type' => 'string', 'required' => true]);
});

it('infers database inputs from named parameters', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::Database, [
        'database' => 'crm',
        'query_template' => 'SELECT * FROM deals WHERE owner = :owner AND stage = :stage',
        'read_only' => true,
    ]));

    expect(collect($contract->inputs)->pluck('name')->all())
        ->toEqualCanonicalizing(['owner', 'stage']);
});

it('derives outputs from response mapping when present', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://api.example.com',
        'method' => 'GET',
        'path' => '/deals',
        'response_mapping' => ['deal_name' => 'data.name', 'deal_amount' => 'data.amount'],
    ]));

    expect($contract->outputs)->toEqualCanonicalizing(['deal_name', 'deal_amount']);
});

it('passes through the safe flag and a legible blast radius', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://slack.com',
        'method' => 'POST',
        'path' => '/messages',
    ], ['safe' => true]));

    expect($contract->safe)->toBeTrue();
    expect($contract->blastRadius)->toContain('slack.com');
    expect($contract->blastRadius)->toContain('POST');
    expect($contract->blastRadius)->toStartWith('May write to');
});

it('serializes to a stable contract shape', function () {
    $contract = $this->resolver->resolve(makeTool(ToolType::RestApi, [
        'base_url' => 'https://api.example.com',
        'method' => 'GET',
        'path' => '/ping',
    ]));

    expect($contract->jsonSerialize())->toHaveKeys([
        'id', 'name', 'integration_id', 'tool_type', 'inputs', 'outputs',
        'effect', 'effect_inferred', 'blast_radius', 'safe', 'typed',
    ]);
});
