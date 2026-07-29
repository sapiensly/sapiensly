<?php

use App\Ai\ChatAgent;
use App\Enums\MembershipRole;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\ImportOrganizationBooksTool;
use App\Models\OrganizationAiContext;
use App\Support\Context\OrganizationContext;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Ai;
use Spatie\Permission\PermissionRegistrar;

/**
 * The MCP half of "read the site once, fill both books". It exists so an agent
 * does not have to parse a home page itself and write both books blind — which
 * is what it had to do while only get/set existed, skipping every rail the web
 * flow applies.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    config()->set('security.ssrf.enabled', false); // the guard has its own tests

    config([
        'filesystems.disks.s3.key' => 'test-key',
        'filesystems.disks.s3.secret' => 'test-secret',
        'filesystems.disks.s3.bucket' => 'test-bucket',
    ]);
    Storage::fake('s3');

    $this->org = mcpOrg();
    $this->owner = mcpMember($this->org, MembershipRole::Owner);

    // Over HTTP, BindMcpTenantContext establishes this from the token user;
    // SapiensServer::actingAs() invokes the tool without the middleware stack,
    // and the import writes to the tenant-scoped cache, which fails closed.
    app(TenantContext::class)->set($this->org->id, $this->owner->id);
});

/** A page carrying both halves: brand signals in the head, prose in the body. */
function fakeBooksSite(string $themeColor = '#0f766e'): void
{
    Http::fake([
        // Most specific first: the icon lives on the same host as the page but
        // has to answer as an image, or it is not importable.
        'acme.example/touch.png' => Http::response('binary-png-bytes', 200, ['Content-Type' => 'image/png']),
        'acme.example*' => Http::response(
            '<html><head><title>Acme</title>'
            .'<meta name="theme-color" content="'.$themeColor.'">'
            .'<link rel="apple-touch-icon" href="/touch.png">'
            .'<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Fraunces">'
            .'</head><body>'.siteCopy().'</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        // The icon lives on the same host but must answer as an image.
        '*' => Http::response('binary-png-bytes', 200, ['Content-Type' => 'image/png']),
    ]);
}

function fakeBooksModel(array $profile = ['descriptor' => 'Moves refrigerated freight.']): void
{
    Ai::fakeAgent(ChatAgent::class, [json_encode($profile)]);
}

function storedContext(string $organizationId): array
{
    return OrganizationAiContext::where('organization_id', $organizationId)->first()?->profile ?? [];
}

it('proposes both books from one reading and writes nothing by default', function () {
    fakeBooksSite();
    fakeBooksModel();

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'acme.example'])
        ->assertOk()
        ->assertSee('#0f766e')
        ->assertSee('Moves refrigerated freight.')
        // The normalized address, not the shorthand that was passed in.
        ->assertSee('https://acme.example');

    expect($this->org->fresh()->brand)->toBeNull()
        ->and(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('fills only the empty fields when asked to apply', function () {
    fakeBooksSite();
    fakeBooksModel(['descriptor' => 'Moves refrigerated freight.', 'industry' => 'Logistics']);

    // Both books already say something the site disagrees with.
    $this->org->update(['brand' => ['accent_color' => '#be185d']]);
    OrganizationAiContext::firstOrNew(['organization_id' => $this->org->id])
        ->setRelation('organization', $this->org)
        ->fill(['profile' => ['descriptor' => 'What we wrote ourselves.']])
        ->recompile()
        ->save();

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example', 'apply' => 'new_only'])
        ->assertOk();

    $brand = $this->org->fresh()->brandbook();
    $context = storedContext($this->org->id);

    // What was empty got filled…
    expect($brand->font)->toBe('serif')
        ->and($context['industry'])->toBe('Logistics')
        // …and what a human had written was left exactly where it was.
        ->and($brand->accentColor)->toBe('#be185d')
        ->and($context['descriptor'])->toBe('What we wrote ourselves.');
});

/** The rule the whole feature is built on, restated for machines. */
it('never resolves a conflict — it reports both sides', function () {
    fakeBooksSite();
    fakeBooksModel(['descriptor' => 'What the website says.']);

    $this->org->update(['brand' => ['accent_color' => '#be185d']]);

    $response = SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example', 'apply' => 'new_only'])
        ->assertOk()
        ->assertSee('conflicts')
        // Both the stored value and what the site proposes, so a human can choose.
        ->assertSee('#be185d')
        ->assertSee('#0f766e');

    expect($response)->not->toBeNull()
        ->and($this->org->fresh()->brandbook()->accentColor)->toBe('#be185d');
});

/**
 * A logo left pointing at somebody else's server breaks the day they reorganize
 * their CDN — and it renders inside chatbot widgets on external sites.
 */
it('adopts the logo and icon onto our own storage when applying', function () {
    fakeBooksSite();
    fakeBooksModel();

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example', 'apply' => 'new_only'])
        ->assertOk();

    $icon = $this->org->fresh()->brandbook()->iconUrl;

    expect($icon)->not->toBeNull()
        ->and($icon)->not->toContain('acme.example')
        ->and($icon)->toContain($this->org->id);

    expect(Storage::disk('s3')->allFiles())->not->toBeEmpty();
});

/** One image that will not copy must not cost the colours from the same reading. */
it('reports an image it could not copy without losing the rest', function () {
    Http::fake([
        'acme.example/touch.png' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response(
            '<html><head><meta name="theme-color" content="#0f766e">'
            .'<link rel="apple-touch-icon" href="/touch.png">'
            .'</head><body>Acme moves freight.</body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
    ]);
    fakeBooksModel();

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example', 'apply' => 'new_only'])
        ->assertOk()
        ->assertSee('assets_failed');

    $brand = $this->org->fresh()->brandbook();

    expect($brand->iconUrl)->toBeNull()
        ->and($brand->accentColor)->toBe('#0f766e');
});

/**
 * The rail an agent writing straight through set_organization_brand skips: a
 * grey theme colour is a real brand colour and a useless accent.
 */
it('refuses an accent that cannot carry a button, and says why', function () {
    fakeBooksSite('#fafafa');
    fakeBooksModel();

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example', 'apply' => 'new_only'])
        ->assertOk()
        ->assertSee('#fafafa');

    expect($this->org->fresh()->brandbook()->accentColor)->toBeNull();
});

/** The Contextbook block is billed on every model call, so it is refused whole. */
it('applies nothing to the contextbook when the draft would blow the token cap', function () {
    fakeBooksSite();

    // Every individual field is clamped on the way in, so an over-cap draft is
    // one that fills the lists — which is exactly what an eager model produces.
    fakeBooksModel([
        'descriptor' => str_repeat('Refrigerated freight for food producers. ', 20),
        'audience' => str_repeat('Mid-size producers shipping to retail chains. ', 20),
        'glossary' => array_map(fn (int $i) => [
            'term' => "term-{$i}-".str_repeat('x', 30),
            'meaning' => str_repeat("meaning {$i} that goes on and on. ", 10),
        ], range(1, 20)),
        'offerings' => array_map(fn (int $i) => [
            'name' => "offering-{$i}-".str_repeat('y', 40),
            'description' => str_repeat("what offering {$i} actually does. ", 10),
        ], range(1, 10)),
        'never' => array_map(
            fn (int $i) => str_repeat("never do the {$i}th forbidden thing. ", 10),
            range(1, 10),
        ),
    ]);

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example', 'apply' => 'new_only'])
        ->assertOk()
        ->assertSee('over the '.OrganizationContext::MAX_TOKENS);

    expect(storedContext($this->org->id))->toBe([])
        // The Brandbook is a separate book and still landed.
        ->and($this->org->fresh()->brandbook()->accentColor)->toBe('#0f766e');
});

it('says which way the read failed instead of guessing', function () {
    fakeBooksModel();

    SapiensServer::actingAs($this->owner)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'javascript:alert(1)'])
        ->assertOk()
        ->assertSee('invalid_url');

    expect($this->org->fresh()->brand)->toBeNull();
});

it('keeps the import away from a plain member', function () {
    $member = mcpMember($this->org, MembershipRole::Member);

    SapiensServer::actingAs($member)
        ->tool(ImportOrganizationBooksTool::class, ['website' => 'https://acme.example'])
        ->assertHasErrors();
});
