<?php

use App\Ai\ChatAgent;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Manifest\AppScaffolder;
use App\Services\Manifest\ScaffoldFailedException;
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

it('hands back every downgrade it applied to the model spec', function () {
    // The model answered with a type the scaffold subset cannot emit. That is a
    // legitimate downgrade — but a SILENT one leaves the author with a plain
    // text field where they asked for something else, which is how an `email`
    // field went unnoticed for the life of the feature.
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->andReturnNull();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Tickets","slug":"tickets","fields":['
        .'{"name":"Asunto","slug":"asunto","type":"string"},'
        .'{"name":"Adjunto","slug":"adjunto","type":"file"}]}],"links":[]}',
    ]);

    $coercions = [];
    $scaffolder = new AppScaffolder(app(AiDefaults::class), $providers);
    $manifest = $scaffolder->scaffold(cfgBaseManifest(), 'A help desk.', User::factory()->create(), $coercions);

    $adjunto = collect($manifest['objects'][0]['fields'])->firstWhere('slug', 'adjunto');

    expect($adjunto['type'])->toBe('string')
        ->and($coercions)->toHaveCount(1)
        ->and($coercions[0])->toContain('"file"');
});

it('reports no downgrade for the contact types the prompt offers', function () {
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->andReturnNull();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    Ai::fakeAgent(ChatAgent::class, [
        '{"objects":[{"name":"Tickets","slug":"tickets","fields":['
        .'{"name":"Correo","slug":"correo","type":"email"}]}],"links":[]}',
    ]);

    $coercions = [];
    $scaffolder = new AppScaffolder(app(AiDefaults::class), $providers);
    $manifest = $scaffolder->scaffold(cfgBaseManifest(), 'A help desk.', User::factory()->create(), $coercions);

    expect($manifest['objects'][0]['fields'][0]['type'])->toBe('email')
        ->and($coercions)->toBe([]);
});

it('fails loudly when the model cannot be reached', function () {
    // It used to answer an empty spec, which the caller saved: "app created"
    // with no objects and no pages, the real cause buried in a log line.
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->andReturnNull();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    Ai::fakeAgent(ChatAgent::class, [
        fn () => throw new RuntimeException('no API key configured'),
    ]);

    $scaffolder = new AppScaffolder(app(AiDefaults::class), $providers);

    expect(fn () => $scaffolder->scaffold(cfgBaseManifest(), 'A help desk.', User::factory()->create()))
        ->toThrow(ScaffoldFailedException::class, 'no API key configured');
});

it('still returns an empty app when the model answers with no objects', function () {
    // A model that ANSWERS "there is nothing here" is not a failure — only an
    // unreachable one is. Pinned so the throw above never widens into this.
    $providers = Mockery::mock(AiProviderService::class);
    $providers->shouldReceive('applyRuntimeConfig')->andReturnNull();
    $providers->shouldReceive('resolveProviderForCatalogModel')->andReturnNull();

    Ai::fakeAgent(ChatAgent::class, ['{"objects":[],"links":[]}']);

    $scaffolder = new AppScaffolder(app(AiDefaults::class), $providers);

    expect($scaffolder->scaffold(cfgBaseManifest(), 'Anything.', User::factory()->create())['objects'])->toBe([]);
});
