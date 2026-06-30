<?php

use App\Enums\AgentStatus;
use App\Enums\IntegrationAuthType;
use App\Enums\IntegrationKind;
use App\Enums\ToolType;
use App\Models\Integration;
use App\Models\Tool;
use App\Models\User;
use App\Services\Security\Ssrf\DnsResolver;
use App\Services\ToolConfigService;
use App\Services\ToolExecutionService;
use App\Services\Tools\LegacyToolConnectionMigrator;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    app()->bind(DnsResolver::class, fn () => new class implements DnsResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.216.34'];
        }
    });

    $this->user = User::factory()->create();
    $this->configService = app(ToolConfigService::class);
    $this->migrator = app(LegacyToolConnectionMigrator::class);
});

function legacyRestTool(User $user, ToolConfigService $config, array $overrides = []): Tool
{
    $plain = array_merge([
        'base_url' => 'https://api.example.com',
        'method' => 'GET',
        'path' => '/orders/{{id}}',
        'auth_type' => 'bearer',
        'auth_config' => ['token' => 'secret-token'],
    ], $overrides);

    return Tool::factory()->create([
        'user_id' => $user->id,
        'type' => ToolType::RestApi,
        'status' => AgentStatus::Active,
        'config' => $config->encryptConfig(ToolType::RestApi, $plain),
    ]);
}

it('distils a legacy database tool into a database connection', function () {
    $plain = [
        'driver' => 'pgsql',
        'host' => 'db.example.com',
        'port' => 5432,
        'database' => 'analytics',
        'username' => 'reader',
        'password' => 'secret',
        'query_template' => 'SELECT * FROM orders WHERE id = :id',
        'read_only' => true,
    ];
    $tool = Tool::factory()->create([
        'user_id' => $this->user->id,
        'type' => ToolType::Database,
        'status' => AgentStatus::Active,
        'config' => $this->configService->encryptConfig(ToolType::Database, $plain),
    ]);

    $result = $this->migrator->migrate();

    expect($result['migrated'])->toBe(1);
    expect($result['integrations_created'])->toBe(1);

    $config = $tool->fresh()->config;
    expect($config)->toHaveKey('integration_id');
    expect($config)->not->toHaveKey('host');
    expect($config['query_template'])->toBe('SELECT * FROM orders WHERE id = :id');
    expect($config)->toHaveKey('_legacy_connection');

    $integration = Integration::find($config['integration_id']);
    expect($integration->kind)->toBe(IntegrationKind::Database);
    expect($integration->auth_config['host'])->toBe('db.example.com');
    expect($integration->auth_config['database'])->toBe('analytics');
});

it('distills a legacy tool into a connection and strips inline config', function () {
    $tool = legacyRestTool($this->user, $this->configService);

    $result = $this->migrator->migrate();

    expect($result['migrated'])->toBe(1);
    expect($result['integrations_created'])->toBe(1);

    $config = $tool->fresh()->config;
    expect($config)->toHaveKey('integration_id');
    expect($config)->not->toHaveKey('base_url');
    expect($config)->not->toHaveKey('auth_config');
    expect($config['method'])->toBe('GET');
    expect($config)->toHaveKey('_legacy_connection');

    $integration = Integration::find($config['integration_id']);
    expect($integration->base_url)->toBe('https://api.example.com');
    expect($integration->auth_type)->toBe(IntegrationAuthType::BearerToken);
    expect($integration->auth_config)->toBe(['token' => 'secret-token']);
});

it('is idempotent — a second run skips already-connected tools', function () {
    $tool = legacyRestTool($this->user, $this->configService);

    $this->migrator->migrate();
    $second = $this->migrator->migrate();

    expect($second['migrated'])->toBe(0);
    expect($second['skipped'])->toBe(1);
});

it('dedupes tools that share a base URL + auth onto one connection', function () {
    legacyRestTool($this->user, $this->configService);
    legacyRestTool($this->user, $this->configService, ['path' => '/customers/{{id}}']);

    $result = $this->migrator->migrate();

    expect($result['migrated'])->toBe(2);
    expect($result['integrations_created'])->toBe(1);
    expect(Integration::count())->toBe(1);
});

it('keeps the tool executable through the new connection', function () {
    $tool = legacyRestTool($this->user, $this->configService);
    $this->migrator->migrate();

    Http::fake(function ($request) {
        expect($request->url())->toContain('api.example.com/orders/123');
        expect($request->header('Authorization')[0])->toBe('Bearer secret-token');

        return Http::response(['ok' => true], 200);
    });

    $result = app(ToolExecutionService::class)->execute($tool->fresh(), ['id' => '123']);

    expect($result->success)->toBeTrue();
});

it('rolls back to the original inline config', function () {
    $tool = legacyRestTool($this->user, $this->configService);
    $this->migrator->migrate();

    $this->migrator->rollback();

    $config = $tool->fresh()->config;
    expect($config)->not->toHaveKey('integration_id');
    expect($config)->toHaveKey('base_url');
    expect($config['base_url'])->toBe('https://api.example.com');
    // The decrypted token is intact after the round-trip.
    $decrypted = $this->configService->decryptConfig(ToolType::RestApi, $config);
    expect($decrypted['auth_config']['token'])->toBe('secret-token');
});
