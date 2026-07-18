<?php

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Manifest\AppScaffolder;
use Laravel\Ai\Ai;

function cfgBaseManifest(): array
{
    return [
        'schema_version' => '1.0.0',
        'id' => 'app_scaffold_cfg',
        'slug' => 'cfg',
        'name' => 'Cfg',
        'version' => 1,
        'objects' => [],
        'pages' => [],
        'permissions' => ['roles' => [['id' => 'rol_admin00001', 'slug' => 'admin', 'name' => 'Admin', 'is_default' => true]]],
        'settings' => ['default_locale' => 'en', 'default_currency' => 'USD'],
    ];
}

it('loads the tenant AI provider credentials before extracting the scaffold spec', function () {
    $user = User::factory()->create();

    // The MCP route's middleware stack has no InjectAiProviderConfig, so the
    // scaffolder must load the tenant's provider credentials itself. Without this
    // the model call has no API key and scaffold_app silently yields an empty app
    // — while the in-app builder (a web request) works. Assert the load happens.
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->with($user)->once();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Contacts","slug":"contacts","fields":[{"name":"Name","slug":"name","type":"string"}]}],"links":[]}',
    ]);

    $scaffolder = new AppScaffolder(app(AiDefaults::class), $providers);
    $manifest = $scaffolder->scaffold(cfgBaseManifest(), 'A CRM with Contacts.', $user);

    // The faked spec flowed through — proving extraction ran with creds loaded.
    expect($manifest['objects'])->toHaveCount(1);
    expect($manifest['objects'][0]['slug'])->toBe('contacts');
});

it('does not attempt to load credentials when no user is available', function () {
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->never();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    Ai::fakeAgent(ChatAgent::class, ['{"objects":[],"links":[]}']);

    $scaffolder = new AppScaffolder(app(AiDefaults::class), $providers);
    $manifest = $scaffolder->scaffold(cfgBaseManifest(), 'Anything.', null);

    expect($manifest['objects'])->toBe([]);
});
