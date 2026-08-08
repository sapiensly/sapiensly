<?php

use App\Models\App;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\User;
use App\Services\Records\BlockDataResolver;
use App\Services\Tools\McpClient;
use Illuminate\Support\Str;

/**
 * A live connected object reads AS THE VIEWING USER, so a dashboard that works
 * for the person who built it shows a viewer who has not authorized… an error
 * about a section that could not be loaded. Nothing is broken and they can fix
 * it themselves, so the block asks them to connect instead.
 *
 * Only ever for a connection whose authorization is per user: an org-level
 * credential that is missing is somebody else's problem, and pointing a
 * technician at a handshake they cannot complete is worse than the error.
 */
function connectTestManifest(App $app, Integration $integration): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => $app->id,
        'slug' => 'live_'.strtolower(Str::random(6)),
        'name' => 'Live',
        'version' => 1,
        'objects' => [[
            'id' => 'obj_livetickets',
            'slug' => 'tickets',
            'name' => 'Ticket',
            'source' => [
                'type' => 'connected',
                'integration_id' => $integration->id,
                'id_path' => 'ticket_id',
                'operations' => ['list' => ['mcp_tool' => 'list_tickets', 'collection_path' => 'tickets']],
                'field_map' => [['field_id' => 'fld_minutesfld', 'external_path' => 'minutes']],
            ],
            'fields' => [['id' => 'fld_minutesfld', 'slug' => 'minutos', 'name' => 'Minutos', 'type' => 'number']],
        ]],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_adminmain', 'slug' => 'admin', 'name' => 'Admin']]],
    ];
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);

    // The tool must never be reached for an unauthorized viewer — a strict mock
    // with no expectations fails the test if it is.
    $this->app->instance(McpClient::class, Mockery::mock(McpClient::class));

    $this->block = [
        'id' => 'blk_livetable',
        'type' => 'table',
        'data_source' => ['object_id' => 'obj_livetickets'],
        'columns' => [['id' => 'col_min', 'field_id' => 'fld_minutesfld']],
    ];
});

it('asks an unauthorized viewer to connect, instead of showing them an error', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);
    $manifest = connectTestManifest($this->testApp, $integration);

    $data = app(BlockDataResolver::class)->resolve(
        $this->testApp,
        [$this->block],
        $manifest,
        ['__actor' => $this->user],
    );

    expect($data['blk_livetable'])->not->toHaveKey('error')
        ->and($data['blk_livetable']['connect'])->toMatchArray([
            'integration_id' => $integration->id,
            'name' => $integration->name,
            'authorize_url' => route('integrations.oauth2.authorize', $integration, absolute: false),
        ]);
});

it('finds the connection through a block that hides its object a level down', function () {
    // A chart carries one object_id per series and a metric_grid one per item;
    // enumerating the known reference sites is how this kind of walk goes stale.
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);
    $manifest = connectTestManifest($this->testApp, $integration);

    $nested = [
        'id' => 'blk_grid',
        'type' => 'metric_grid',
        'items' => [[
            'id' => 'itm_one',
            'label' => 'Minutos',
            'query' => ['object_id' => 'obj_livetickets'],
            'aggregation' => 'sum',
            'field_id' => 'fld_minutesfld',
        ]],
    ];

    $data = app(BlockDataResolver::class)->resolve(
        $this->testApp,
        [$nested],
        $manifest,
        ['__actor' => $this->user],
    );

    expect($data['blk_grid']['connect']['integration_id'])->toBe($integration->id);
});

it('says nothing to a viewer who has already connected', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);
    IntegrationUserToken::create([
        'user_id' => $this->user->id,
        'integration_id' => $integration->id,
        'auth_config' => ['access_token' => 'tok', 'expires_at' => time() + 3600],
    ]);

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andReturn(['tickets' => [
        ['ticket_id' => 'T-1', 'minutes' => 30],
    ]]);
    $this->app->instance(McpClient::class, $mcp);

    $data = app(BlockDataResolver::class)->resolve(
        $this->testApp,
        [$this->block],
        connectTestManifest($this->testApp, $integration),
        ['__actor' => $this->user],
    );

    expect($data['blk_livetable'])->not->toHaveKey('connect')
        ->and($data['blk_livetable']['rows'])->toHaveCount(1);
});

it('keeps the plain error for a credential the viewer cannot supply', function () {
    // A shared bearer credential still in draft: the viewer has no handshake to
    // complete, so offering one would be a dead end.
    $integration = Integration::factory()->forUser($this->user)->create([
        'is_mcp' => true,
        'auth_type' => 'bearer',
        'auth_config' => [],
        'status' => 'draft',
    ]);

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andThrow(new RuntimeException('401 Unauthorized'));
    $this->app->instance(McpClient::class, $mcp);

    $data = app(BlockDataResolver::class)->resolve(
        $this->testApp,
        [$this->block],
        connectTestManifest($this->testApp, $integration),
        ['__actor' => $this->user],
    );

    expect($data['blk_livetable'])->not->toHaveKey('connect')
        ->and($data['blk_livetable'])->toHaveKey('error');
});

it('says nothing to a portal visitor, who holds no token and has nowhere to go', function () {
    $integration = Integration::factory()->oauth2AuthCode()->forUser($this->user)->create(['is_mcp' => true]);

    $mcp = Mockery::mock(McpClient::class);
    $mcp->shouldReceive('callToolData')->andThrow(new RuntimeException('401 Unauthorized'));
    $this->app->instance(McpClient::class, $mcp);

    $data = app(BlockDataResolver::class)->resolve(
        $this->testApp,
        [$this->block],
        connectTestManifest($this->testApp, $integration),
        [], // no __actor: a public/portal render
    );

    expect($data['blk_livetable'])->not->toHaveKey('connect');
});
