<?php

use App\Models\Integration;
use App\Models\User;
use App\Services\Connected\ConnectedObjectAuthoring;
use App\Services\Connected\ConnectedObjectReader;
use App\Services\Integrations\IntegrationCaller;
use App\Services\Manifest\ManifestValidator;
use App\Services\Records\ExpressionResolver;
use App\Services\Tools\McpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Read-path slice for an MCP-backed connected object: a dashboard reads a
 * support desk (etc.) LIVE by calling an MCP tool as the acting viewer, mapping
 * the structured rows through the shared field_map/id_path — no seeding.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->integration = Integration::factory()->forUser($this->user)->create([
        'base_url' => 'https://mcp.example.com/v1',
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'TKN'],
        'status' => 'draft',
    ]);
});

function mcpTicketObject(string $integrationId): array
{
    return [
        'id' => 'obj_ticketobj',
        'slug' => 'tickets',
        'name' => 'Ticket',
        'fields' => [
            ['id' => 'fld_statusfield', 'slug' => 'status', 'name' => 'Status', 'type' => 'string'],
            ['id' => 'fld_minutesfield', 'slug' => 'minutes', 'name' => 'Minutes', 'type' => 'number'],
        ],
        'source' => [
            'type' => 'connected',
            'integration_id' => $integrationId,
            'id_path' => 'ticket_id',
            'operations' => ['list' => ['mcp_tool' => 'list_tickets', 'arguments' => ['limit' => 500], 'collection_path' => 'tickets']],
            'field_map' => [
                ['field_id' => 'fld_statusfield', 'external_path' => 'status'],
                ['field_id' => 'fld_minutesfield', 'external_path' => 'metrics.resolution_minutes'],
            ],
        ],
    ];
}

it('reads with the acting viewer so a per-user OAuth source resolves their token', function () {
    $viewer = $this->user;
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args, $max = null) => $name === 'list_tickets'
            && $user?->is($viewer) === true                 // the viewer, not null
            && ($args['limit'] ?? null) === 500
            && ($config['integration_id'] ?? null) === $this->integration->id)
        ->andReturn(['tickets' => [
            ['ticket_id' => 'T1', 'status' => 'abierto', 'metrics' => ['resolution_minutes' => 30]],
            ['ticket_id' => 'T2', 'status' => 'cerrado', 'metrics' => ['resolution_minutes' => 90]],
        ]]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(mcpTicketObject($this->integration->id), $this->integration, [], $viewer);

    expect($result['ok'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(2)
        ->and($result['rows'][0]['status'])->toBe('abierto')
        ->and($result['rows'][0]['minutes'])->toBe(30)
        ->and($result['rows'][0]['_external_id'])->toBe('T1')
        ->and($result['rows'][1]['status'])->toBe('cerrado');
});

it('turns a per-user OAuth failure into an authorize-the-connection message', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()->andThrow(new RuntimeException('OAuth 2.0 MCP tools require a user context to resolve the token.'));

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(mcpTicketObject($this->integration->id), $this->integration);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('authorize the connection');
});

it('reports did-not-return-JSON-rows when the tool answers in prose only', function () {
    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()->andReturnNull();

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(mcpTicketObject($this->integration->id), $this->integration);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('did not return JSON rows');
});

it('accepts a connected object with an mcp_tool source in the manifest schema', function () {
    $manifest = [
        'schema_version' => '1.0.0',
        'id' => 'app_'.strtolower((string) Str::ulid()),
        'slug' => 'mini_'.strtolower(Str::random(6)),
        'name' => 'Live tickets',
        'version' => 1,
        'objects' => [mcpTicketObject('itg_yuhugo')],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminrole', 'slug' => 'admin', 'name' => 'Admin']]],
    ];

    expect((new ManifestValidator)->validate($manifest)->valid)->toBeTrue();
});

it('resolves rolling-window expressions in operation arguments per read', function () {
    $today = now()->utc()->toDateString();
    $monthAgo = now()->utc()->subDays(30)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = [
        'from' => '{{days_ago(30)}}',
        'to' => '{{today()}}',
        'granularity' => 'weekly',
    ];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $monthAgo
            && $args['to'] === $today
            && $args['granularity'] === 'weekly')
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, [], $this->user);

    expect($result['ok'])->toBeTrue();
});

it('pushes the picked date-range preset down into the source start-date argument', function () {
    // The object bakes a fixed 6-month fetch window; the dashboard's date filter
    // asks for a year. Without the push-down "Año" would show the same ~6 months.
    $yearAgo = now()->utc()->subYear()->toDateString();
    $today = now()->utc()->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = [
        'from' => '{{days_ago(183)}}',
        'to' => '{{today()}}',
        'granularity' => 'weekly',
    ];

    // The block's data-source filter as the compiler wires it for a connected
    // dashboard (a range_start gte leaf), plus the request context choosing 1y.
    $query = ['filter' => ['op' => 'gte', 'field_id' => 'fld_datefield', 'value_expression' => "{{range_start(default(params.range, '1y'))}}"]];
    $context = ['params' => (object) ['range' => '1y']];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $yearAgo   // widened to the preset, not the 183d bake
            && $args['to'] === $today
            && $args['granularity'] === 'weekly')
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, $query, $this->user, $context);

    expect($result['ok'])->toBeTrue();
});

it('leaves the source window untouched when the block has no range filter', function () {
    // No range_start leaf ⇒ the baked window stands; nothing to push down.
    $sixMonthsAgo = now()->utc()->subDays(183)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['from' => '{{days_ago(183)}}', 'to' => '{{today()}}'];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $sixMonthsAgo)
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, ['filter' => ['op' => 'eq', 'field_id' => 'fld_statusfield', 'value' => 'abierto']], $this->user, ['params' => (object) ['range' => '1y']]);

    expect($result['ok'])->toBeTrue();
});

it('INJECTS from/to when the object omits them but the tool schema declares them', function () {
    // The nps_fable_dsh root cause: the weekly series called the tool with only
    // {granularity: weekly}, so the tool's adaptive default (12 weeks) capped it
    // — "Año" showed ~13 rows. The tool DOES accept from/to, so we inject them.
    $yearAgo = now()->utc()->subYear()->toDateString();
    $today = now()->utc()->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['mcp_tool'] = 'get-nps-time-series-tool';
    $object['source']['operations']['list']['arguments'] = ['granularity' => 'weekly']; // NO window

    $query = ['filter' => ['op' => 'gte', 'field_id' => 'fld_datefield', 'value_expression' => "{{range_start(default(params.range, '1y'))}}"]];
    $context = ['params' => (object) ['range' => '1y']];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->once()->andReturn([
        ['name' => 'get-nps-time-series-tool', 'description' => '', 'input_schema' => ['type' => 'object', 'properties' => [
            'granularity' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
        ]]],
    ]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $yearAgo   // injected
            && $args['to'] === $today
            && $args['granularity'] === 'weekly')
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, $query, $this->user, $context);

    expect($result['ok'])->toBeTrue();
});

it('retries within the tool max range when the pushed window overshoots', function () {
    // Prod: "Año" pushed a 365-day window into get-portafolio-ots-tool, whose
    // cap is 92 days, so the tool errored and the chart failed. We parse the
    // stated max and retry at the widest legal window instead.
    $cappedFrom = now()->utc()->subDays(91)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['mcp_tool'] = 'get-portafolio-ots-tool';
    $object['source']['operations']['list']['arguments'] = ['from' => '{{days_ago(30)}}', 'to' => '{{today()}}'];

    $query = ['filter' => ['op' => 'gte', 'field_id' => 'fld_datefield', 'value_expression' => "{{range_start(default(params.range, '30d'))}}"]];
    $context = ['params' => (object) ['range' => '1y']]; // user picked Año → 365 days

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === now()->utc()->subYear()->toDateString())
        ->andThrow(new RuntimeException("The MCP tool 'get-portafolio-ots-tool' returned an error: El rango máximo permitido es de 92 días."));
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $cappedFrom) // retried within the cap
        ->andReturn(['tickets' => [['ticket_id' => 'T1', 'status' => 'ok', 'metrics' => ['resolution_minutes' => 5]]]]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, $query, $this->user, $context);

    expect($result['ok'])->toBeTrue()->and($result['rows'])->toHaveCount(1);
});

it('switches to DAILY granularity for a short range when the tool offers it', function () {
    // "Hoy"/"7 días" over a WEEKLY series is one bucket — so a short window asks
    // the source for daily rows if its granularity param exposes a daily value.
    $weekAgo = now()->utc()->subDays(7)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['mcp_tool'] = 'get-nps-time-series-tool';
    $object['source']['operations']['list']['arguments'] = ['granularity' => 'weekly']; // baked weekly

    $query = ['filter' => ['op' => 'gte', 'field_id' => 'fld_datefield', 'value_expression' => "{{range_start(default(params.range, '30d'))}}"]];
    $context = ['params' => (object) ['range' => '7d']];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([
        ['name' => 'get-nps-time-series-tool', 'description' => '', 'input_schema' => ['type' => 'object', 'properties' => [
            'granularity' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly']],
            'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
        ]]],
    ]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['granularity'] === 'daily' // switched
            && $args['from'] === $weekAgo)                                                // window injected too
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    expect($reader->list($object, $this->integration, $query, $this->user, $context)['ok'])->toBeTrue();
});

it('keeps the baked granularity when the tool has no daily option', function () {
    // The tool's granularity enum offers no daily value → don't force one.
    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['mcp_tool'] = 'get-monthly-tool';
    $object['source']['operations']['list']['arguments'] = ['granularity' => 'monthly'];

    $query = ['filter' => ['op' => 'gte', 'field_id' => 'fld_datefield', 'value_expression' => "{{range_start(default(params.range, '30d'))}}"]];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->andReturn([
        ['name' => 'get-monthly-tool', 'description' => '', 'input_schema' => ['type' => 'object', 'properties' => [
            'granularity' => ['type' => 'string', 'enum' => ['monthly', 'quarterly']], // no daily
            'from' => ['type' => 'string'], 'to' => ['type' => 'string'],
        ]]],
    ]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['granularity'] === 'monthly') // untouched
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    expect($reader->list($object, $this->integration, $query, $this->user, ['params' => (object) ['range' => '7d']])['ok'])->toBeTrue();
});

it('does NOT inject a window the tool schema does not declare (no blind guessing)', function () {
    // A tool with no from/to in its schema must not receive one — injecting an
    // unknown property could make a strict tool reject the whole call.
    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['limit' => 500]; // no window, tool has none

    $query = ['filter' => ['op' => 'gte', 'field_id' => 'fld_datefield', 'value_expression' => "{{range_start(default(params.range, '1y'))}}"]];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('listTools')->once()->andReturn([
        ['name' => 'list_tickets', 'description' => '', 'input_schema' => ['type' => 'object', 'properties' => [
            'limit' => ['type' => 'integer'], 'status' => ['type' => 'string'],
        ]]],
    ]);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args === ['limit' => 500]) // untouched
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, $query, $this->user, ['params' => (object) ['range' => '1y']]);

    expect($result['ok'])->toBeTrue();
});

it('falls back to the PAGE range when the block has no date filter of its own', function () {
    // Prod yuhunps: nps_by_dimension exposes NO date field, so its KPI/hbar
    // blocks carry no range_start leaf — the tool's baked 30d window stayed
    // frozen while the page picker said 90d (subtitle still claiming "en la
    // ventana"). BlockDataResolver threads the page's date_range control as
    // __page_range_start_expr; the push-down uses it when the block is silent.
    $yearAgo = now()->utc()->subYear()->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['from' => '{{days_ago(30)}}', 'to' => '{{today()}}'];

    $context = [
        'params' => (object) ['range' => '1y'],
        '__page_range_start_expr' => "{{range_start(default(params.range, '90d'))}}",
    ];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')
        ->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $yearAgo)  // page preset, not the 30d bake
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list(
        $object,
        $this->integration,
        ['filter' => ['op' => 'eq', 'field_id' => 'fld_statusfield', 'value' => 'abierto']],
        $this->user,
        $context,
    );

    expect($result['ok'])->toBeTrue();
});

it('synthesizes a required date window the object never authored and retries once', function () {
    // Prod yuhuticket: an agent recreated top_root_causes by hand via
    // propose_change and dropped from/to — args the auto-authoring synthesizes
    // from the tool schema — so every read died with "The from field is
    // required. The to field is required." while the dashboard showed error
    // cards over a perfectly healthy source.
    $monthAgo = now()->utc()->subDays(30)->toDateString();
    $today = now()->utc()->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['top_n' => 15, 'dimension' => 'cause'];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()
        ->withArgs(fn ($config, $user, $name, $args) => ! array_key_exists('from', $args))
        ->andThrow(new RuntimeException("The MCP tool 'list_tickets' returned an error: The from field is required. The to field is required."));
    $mcp->shouldReceive('callToolData')->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $monthAgo
            && $args['to'] === $today
            && $args['dimension'] === 'cause')
        ->andReturn(['tickets' => [['ticket_id' => 't1', 'status' => 'open', 'metrics' => ['resolution_minutes' => 5]]]]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, [], $this->user);

    expect($result['ok'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(1);
});

it('does not retry when the required field is not a window key', function () {
    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['top_n' => 15];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()
        ->andThrow(new RuntimeException("The MCP tool 'list_tickets' returned an error: The dimension field is required."));

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, [], $this->user);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('dimension');
});

it('samples the PREVIOUS window of an authored object: same tool, span shifted back', function () {
    $sixtyAgo = now()->utc()->startOfDay()->subDays(60)->toDateString();
    $thirtyAgo = now()->utc()->startOfDay()->subDays(30)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['from' => '{{days_ago(30)}}', 'to' => '{{today()}}', 'dimension' => 'cause'];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $sixtyAgo
            && $args['to'] === $thirtyAgo
            && $args['dimension'] === 'cause')
        ->andReturn(['tickets' => [['ticket_id' => 'p1', 'status' => 'open', 'metrics' => ['resolution_minutes' => 4]]]]);
    $this->app->instance(McpClient::class, $mcp);

    $rows = app(ConnectedObjectAuthoring::class)
        ->previousWindowRows($this->user, $this->integration, $object);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['ticket_id'])->toBe('p1'); // RAW shape, as the sampler feeds facts
});

it('previous window is a no-op for a window-less tool (granularity-only series)', function () {
    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['granularity' => 'weekly'];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldNotReceive('callToolData');
    $this->app->instance(McpClient::class, $mcp);

    expect(app(ConnectedObjectAuthoring::class)
        ->previousWindowRows($this->user, $this->integration, $object))->toBe([]);
});

it('a __window previous read shifts the RESOLVED window one span back', function () {
    $sixtyAgo = now()->utc()->subDays(60)->toDateString();
    $thirtyAgo = now()->utc()->subDays(30)->toDateString();

    $object = mcpTicketObject($this->integration->id);
    $object['source']['operations']['list']['arguments'] = ['from' => '{{days_ago(30)}}', 'to' => '{{today()}}'];

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->once()
        ->withArgs(fn ($config, $user, $name, $args) => $args['from'] === $sixtyAgo && $args['to'] === $thirtyAgo)
        ->andReturn(['tickets' => []]);

    $reader = new ConnectedObjectReader(app(IntegrationCaller::class), $mcp, app(ExpressionResolver::class));
    $result = $reader->list($object, $this->integration, [], $this->user, ['__window' => 'previous']);

    expect($result['ok'])->toBeTrue();
});

it('prefetch pools the distinct MCP reads once — list() then serves from memory', function () {
    // A complex dashboard used to pay 7-11 SERIAL MCP round-trips before its
    // first byte. prefetch() resolves the same memo keys as list() and warms
    // them concurrently; the block loop then reads memory.
    Http::fake([
        'mcp.example.com/*' => Http::sequence()
            ->push(['result' => []], 200, ['Mcp-Session-Id' => 's1'])   // initialize
            ->push('', 202)                                              // notifications/initialized
            ->push(['result' => ['structuredContent' => ['tickets' => [
                ['ticket_id' => 'T1', 'status' => 'abierto', 'metrics' => ['resolution_minutes' => 30]],
            ]]]], 200)
            ->push(['result' => ['structuredContent' => ['agents' => [
                ['agent_id' => 'A1', 'name' => 'Eva'],
            ]]]], 200),
    ]);

    $tickets = mcpTicketObject($this->integration->id);
    $agents = [
        'id' => 'obj_agentsobj', 'slug' => 'agents', 'name' => 'Agent',
        'fields' => [['id' => 'fld_agentname00', 'slug' => 'name', 'name' => 'Name', 'type' => 'string']],
        'source' => [
            'type' => 'connected', 'integration_id' => $this->integration->id, 'id_path' => 'agent_id',
            'operations' => ['list' => ['mcp_tool' => 'list_agents', 'arguments' => [], 'collection_path' => 'agents']],
            'field_map' => [['field_id' => 'fld_agentname00', 'external_path' => 'name']],
        ],
    ];

    $reader = app(ConnectedObjectReader::class);
    $reader->prefetch([
        ['object' => $tickets, 'integration' => $this->integration, 'query' => [], 'actor' => $this->user, 'context' => []],
        ['object' => $agents, 'integration' => $this->integration, 'query' => [], 'actor' => $this->user, 'context' => []],
        // Same read referenced by a second block — must not call twice.
        ['object' => $tickets, 'integration' => $this->integration, 'query' => [], 'actor' => $this->user, 'context' => []],
    ]);

    Http::assertSentCount(4); // init + notify + 2 pooled calls

    $a = $reader->list($tickets, $this->integration, [], $this->user, []);
    $b = $reader->list($agents, $this->integration, [], $this->user, []);

    Http::assertSentCount(4); // memo hits — zero extra HTTP
    expect($a['ok'])->toBeTrue()
        ->and($a['rows'][0]['status'] ?? null)->toBe('abierto')
        ->and($b['ok'])->toBeTrue()
        ->and($b['rows'][0]['name'] ?? null)->toBe('Eva');
});
