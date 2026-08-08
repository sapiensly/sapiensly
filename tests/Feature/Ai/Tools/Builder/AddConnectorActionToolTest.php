<?php

use App\Ai\Tools\Builder\AddConnectorActionTool;
use App\Enums\ConnectorEffect;
use App\Enums\ToolType;
use App\Models\Integration;
use App\Models\Tool as ToolModel;
use App\Models\User;
use App\Services\Connectors\ConnectorActionResolver;
use App\Services\Connectors\ConnectorCallGate;
use App\Services\Integrations\IntegrationCaller;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * Creating the typed action a connector.call references.
 *
 * The builder could create the CONNECTION and never the actions on it, so
 * list_connector_actions came back empty on an API the model had just connected
 * and the step could not be composed at all — the only way to fill that list was
 * the integrations admin, which a conversation cannot reach.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['organization_id' => null]);
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://api.example.com',
        'is_mcp' => false,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);
});

function aca_tool(User $user): AddConnectorActionTool
{
    return new AddConnectorActionTool(
        app(IntegrationCaller::class),
        app(ConnectorActionResolver::class),
        $user,
    );
}

it('creates a write action a connector.call can reference', function () {
    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Post a Slack message',
        'method' => 'POST',
        'path' => '/chat.postMessage',
        'request_body_template' => '{"channel": "{{channel}}", "text": "{{message}}"}',
        'response_mapping' => ['ts' => 'ts'],
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['effect'])->toBe('write')
        // The placeholders in the body become the action's typed inputs — this
        // is what a connector.call step fills in.
        ->and(collect($result['inputs'])->pluck('name')->all())->toEqualCanonicalizing(['channel', 'message'])
        ->and($result['outputs'])->toBe(['ts']);

    $action = ToolModel::find($result['action_id']);

    expect($action)->not->toBeNull()
        ->and($action->type)->toBe(ToolType::RestApi)
        ->and($action->config['integration_id'])->toBe($this->integration->id)
        ->and($action->config['method'])->toBe('POST');

    // And it is discoverable exactly where the model looks for it.
    $listed = ToolModel::query()
        ->forAccountContext($this->user)
        ->where('config->integration_id', $this->integration->id)
        ->pluck('id');
    expect($listed)->toContain($action->id);
});

it('never marks a new action safe, so a write stays behind the approval gate', function () {
    // `safe` is what lets a write skip propose-don't-mutate. A model able to set
    // it could switch off the gate on an action it invented in the same breath,
    // so it is not on the schema at all — passing it changes nothing.
    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Refund an order',
        'method' => 'DELETE',
        'path' => '/orders/{{order_id}}',
        'safe' => true,
    ])), true);

    $action = ToolModel::find($result['action_id']);
    $decision = app(ConnectorCallGate::class)->inspect($action);

    expect($action->safe)->toBeFalse()
        ->and($decision->contract->effect)->toBe(ConnectorEffect::Write)
        ->and($decision->mustGate)->toBeTrue();
});

it('verifies a read by calling it once', function () {
    Http::fake(['https://api.example.com/*' => Http::response(['ok' => true], 200)]);

    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Look up a customer',
        'method' => 'GET',
        'path' => '/customers?limit=1',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['effect'])->toBe('read')
        ->and($result['verified'])->toBeTrue();

    Http::assertSentCount(1);
});

it('refuses a read the endpoint does not answer, rather than creating an action nobody can call', function () {
    Http::fake(['https://api.example.com/*' => Http::response(['error' => 'nope'], 404)]);

    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Broken lookup',
        'method' => 'GET',
        'path' => '/nope',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('404')
        ->and(ToolModel::query()->where('name', 'Broken lookup')->exists())->toBeFalse();
});

it('does not call a write to find out whether it works', function () {
    // Testing a DELETE by running it would BE the deletion.
    Http::fake();

    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Cancel a subscription',
        'method' => 'DELETE',
        'path' => '/subscriptions/42',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['verified'])->toBeFalse()
        ->and($result['message'])->toContain('approval');

    Http::assertNothingSent();
});

it('leaves a templated read unverified instead of calling a literal placeholder', function () {
    Http::fake();

    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Read one order',
        'method' => 'GET',
        'path' => '/orders/{{order_id}}',
    ])), true);

    expect($result['ok'])->toBeTrue()
        ->and($result['verified'])->toBeFalse()
        ->and(collect($result['inputs'])->pluck('name')->all())->toBe(['order_id']);

    Http::assertNothingSent();
});

it('sends an MCP connection away, because its operations already exist', function () {
    $mcp = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'active',
    ]);

    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $mcp->id,
        'name' => 'Anything',
        'method' => 'POST',
        'path' => '/x',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('MCP server');
});

it('cannot author an action on another tenant\'s connection', function () {
    $stranger = User::factory()->create(['organization_id' => null]);

    $result = json_decode(aca_tool($stranger)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Sneaky',
        'method' => 'POST',
        'path' => '/x',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('not found')
        ->and(ToolModel::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

it('refuses a method that is not a method', function () {
    $result = json_decode(aca_tool($this->user)->handle(new ToolRequest([
        'integration_id' => $this->integration->id,
        'name' => 'Odd',
        'method' => 'FETCH',
        'path' => '/x',
    ])), true);

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'][0]['message'])->toContain('FETCH');
});
