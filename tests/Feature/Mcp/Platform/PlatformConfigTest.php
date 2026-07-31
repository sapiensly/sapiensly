<?php

use App\Mcp\McpContext;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Platform\GetAccessPolicyTool;
use App\Mcp\Tools\Platform\GetAiDefaultsTool;
use App\Mcp\Tools\Platform\GetCloudConfigTool;
use App\Mcp\Tools\Platform\ListCatalogModelsTool;
use App\Mcp\Tools\Platform\ListMcpTokensTool;
use App\Mcp\Tools\Platform\ListPlatformProvidersTool;
use App\Mcp\Tools\Platform\ManageCatalogModelTool;
use App\Mcp\Tools\Platform\RevokeMcpTokenTool;
use App\Mcp\Tools\Platform\RunPlatformMaintenanceTool;
use App\Mcp\Tools\Platform\SetAccessPolicyTool;
use App\Mcp\Tools\Platform\SetAiDefaultsTool;
use App\Models\AiCatalogModel;
use App\Models\McpAccessToken;
use App\Models\PlatformAuditLog;
use App\Services\Ai\AiDefaults;
use App\Services\Platform\AccessPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg('Acme');
    $this->sysadmin = mcpSysadmin($this->org);
    mcpActingContext(['platform:admin']);
});

it('reads the access policy with its hardening checklist', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(GetAccessPolicyTool::class, [])
        ->assertOk()
        ->assertSee('posture')
        ->assertSee('two_factor');
});

it('changes the access policy and warns about the blast radius', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAccessPolicyTool::class, ['two_factor_required' => true])
        ->assertOk()
        // Nobody in this fixture has enrolled, so the count must be surfaced.
        ->assertSee('second factor');

    expect(app(AccessPolicy::class)->read()['twoFactorRequired'])->toBeTrue();
    expect(PlatformAuditLog::where('action', 'set_access_policy')->exists())->toBeTrue();
});

it('calls out an enabled but empty IP allowlist rather than reporting protection', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAccessPolicyTool::class, ['ip_allowlist_enabled' => true, 'ip_allowlist' => []])
        ->assertOk()
        ->assertSee('enforces nothing');
});

it('writes through the same settings keys the admin screen reads', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAccessPolicyTool::class, ['registration_open' => false])
        ->assertOk();

    $this->actingAs($this->sysadmin)
        ->get(route('admin.access.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('settings.registrationOpen', false));
});

it('refuses an access-policy call that changes nothing', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAccessPolicyTool::class, [])
        ->assertHasErrors();
});

it('lists providers without ever returning a key in full', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListPlatformProvidersTool::class, [])
        ->assertOk()
        ->assertSee('anthropic')
        ->assertSee('key_source');
});

it('lists catalog models and flags the unpriced ones', function () {
    AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'test-model-x',
        'label' => 'Test Model X',
        'capability' => 'chat',
        'is_enabled' => false,
    ]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListCatalogModelsTool::class, ['search' => 'test-model-x'])
        ->assertOk()
        ->assertSee('unpriced');
});

it('refuses to enable a model whose provider has no key', function () {
    $model = AiCatalogModel::create([
        'driver' => 'deepseek',
        'model_id' => 'orphan-model',
        'label' => 'Orphan',
        'capability' => 'chat',
        'is_enabled' => false,
    ]);

    config(['ai.providers.deepseek.key' => '']);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageCatalogModelTool::class, ['model_id' => (string) $model->id, 'action' => 'enable'])
        ->assertHasErrors();

    expect($model->refresh()->is_enabled)->toBeFalse();
});

it('reports which module each model serves and flags broken defaults', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(GetAiDefaultsTool::class, [])
        ->assertOk()
        ->assertSee('landing_director')
        ->assertSee('capability');
});

it('refuses to route a module at a model of the wrong capability', function () {
    $embedding = AiCatalogModel::create([
        'driver' => 'openai',
        'model_id' => 'text-embedding-test',
        'label' => 'Embedder',
        'capability' => 'embeddings',
        'is_enabled' => true,
    ]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAiDefaultsTool::class, ['module' => 'chat', 'model_id' => (string) $embedding->id])
        ->assertHasErrors();

    expect(app(AiDefaults::class)->primaryId('chat'))->not->toBe((string) $embedding->id);
});

it('refuses to route a module at a disabled model', function () {
    $disabled = AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'chat-but-off',
        'label' => 'Off',
        'capability' => 'chat',
        'is_enabled' => false,
    ]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAiDefaultsTool::class, ['module' => 'builder', 'model_id' => (string) $disabled->id])
        ->assertHasErrors();
});

it('sets a module default and then refuses to disable the model behind it', function () {
    $model = AiCatalogModel::create([
        'driver' => 'anthropic',
        'model_id' => 'chat-in-use',
        'label' => 'In Use',
        'capability' => 'chat',
        'is_enabled' => true,
    ]);

    config(['ai.providers.anthropic.key' => 'sk-test-key-value-1234567890']);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(SetAiDefaultsTool::class, ['module' => 'flows', 'model_id' => (string) $model->id])
        ->assertOk();

    expect(app(AiDefaults::class)->primaryId('flows'))->toBe((string) $model->id);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ManageCatalogModelTool::class, ['model_id' => (string) $model->id, 'action' => 'disable'])
        ->assertHasErrors();

    expect($model->refresh()->is_enabled)->toBeTrue();
});

it('describes the tenancy layout', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(GetCloudConfigTool::class, [])
        ->assertOk()
        ->assertSee('tenancy')
        ->assertSee('tenant_app');
});

it('lists MCP tokens and marks the platform-admin ones', function () {
    mcpToken($this->org, $this->sysadmin, ['abilities' => ['platform:admin']]);

    SapiensServer::actingAs($this->sysadmin)
        ->tool(ListMcpTokensTool::class, ['organization' => $this->org->slug])
        ->assertOk()
        ->assertSee('platform_admin');
});

it('revokes a token but never the one authenticating this call', function () {
    mcpToken($this->org, $this->sysadmin, ['name' => 'other']);
    $other = McpAccessToken::where('name', 'other')->first();

    // Bind the live context to a DIFFERENT saved token, then try to revoke it.
    $current = McpAccessToken::create([
        'user_id' => $this->sysadmin->id,
        'organization_id' => $this->org->id,
        'name' => 'current',
        'token' => McpAccessToken::generateToken(),
        'abilities' => ['platform:admin'],
    ]);
    app()->instance(McpContext::class, new McpContext($current));

    SapiensServer::actingAs($this->sysadmin)
        ->tool(RevokeMcpTokenTool::class, ['token_id' => $current->id])
        ->assertHasErrors();

    expect(McpAccessToken::find($current->id))->not->toBeNull();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(RevokeMcpTokenTool::class, ['token_id' => $other->id])
        ->assertOk();

    expect(McpAccessToken::find($other->id))->toBeNull();
});

it('lists maintenance operations and refuses anything off the allowlist', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(RunPlatformMaintenanceTool::class, [])
        ->assertOk()
        ->assertSee('cache:clear')
        ->assertSee('migrate:pretend');

    SapiensServer::actingAs($this->sysadmin)
        ->tool(RunPlatformMaintenanceTool::class, ['operation' => 'db:wipe'])
        ->assertHasErrors();

    SapiensServer::actingAs($this->sysadmin)
        ->tool(RunPlatformMaintenanceTool::class, ['operation' => 'cache:clear --force; rm -rf /'])
        ->assertHasErrors();
});

it('requires explicit confirmation before a disruptive operation', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(RunPlatformMaintenanceTool::class, ['operation' => 'queue:restart'])
        ->assertHasErrors();

    expect(PlatformAuditLog::where('action', 'run_platform_maintenance')->exists())->toBeFalse();
});

it('runs a safe maintenance operation and records it', function () {
    SapiensServer::actingAs($this->sysadmin)
        ->tool(RunPlatformMaintenanceTool::class, ['operation' => 'view:clear'])
        ->assertOk();

    expect(PlatformAuditLog::where('action', 'run_platform_maintenance')->exists())->toBeTrue();
});
