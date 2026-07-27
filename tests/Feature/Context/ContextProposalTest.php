<?php

use App\Ai\ChatAgent;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationAiContext;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\Context\ContextProposalService;
use App\Support\Draft\DraftDiff;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-'.Str::lower(Str::random(6))]);
    $this->owner = User::factory()->create(['email_verified_at' => now(), 'organization_id' => $this->org->id]);
    OrganizationMembership::create([
        'organization_id' => $this->org->id, 'user_id' => $this->owner->id,
        'role' => MembershipRole::Owner, 'status' => MembershipStatus::Active,
    ]);
});

it('drafts a contextbook from the website and normalizes what the model returns', function () {
    config()->set('security.ssrf.enabled', false); // the guard has its own tests; here we exercise the fetch
    Http::fake(['*' => Http::response(
        '<html><head><title>Acme</title></head><body>Acme moves refrigerated freight.</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'descriptor' => 'Moves refrigerated freight for food producers.',
        'currency' => 'mxn',
        // The model is not trusted: an unusable value is dropped, not stored.
        'formality' => 'shouty',
        'glossary' => [['term' => 'guia', 'meaning' => 'the shipment document']],
    ])]);

    $draft = app(ContextProposalService::class)->propose('https://acme.example', 'Freight company', $this->owner);

    expect($draft['generated'])->toBeTrue()
        ->and($draft['sources'])->toBe(['brief', 'website'])
        ->and($draft['profile']['descriptor'])->toBe('Moves refrigerated freight for food producers.')
        ->and($draft['profile']['currency'])->toBe('MXN')
        ->and($draft['profile']['formality'])->toBeNull()
        ->and($draft['profile']['glossary'])->toBe([['term' => 'guia', 'meaning' => 'the shipment document']]);
});

/**
 * Everything below is a net under a prompt rule, and each one is here because a
 * live draft from a real site did exactly this.
 */
it('drops the placeholders a model writes when it means "I do not know"', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('<html><body>Acme.</body></html>', 200, ['Content-Type' => 'text/html'])]);
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'descriptor' => 'Moves freight.',
        'size' => 'No especificado',
        'industry' => 'N/A',
        'audience' => 'unknown',
    ])]);

    $draft = app(ContextProposalService::class)->propose('https://acme.example', '', $this->owner);

    expect($draft['profile']['descriptor'])->toBe('Moves freight.')
        ->and($draft['profile']['size'])->toBeNull()
        ->and($draft['profile']['industry'])->toBeNull()
        ->and($draft['profile']['audience'])->toBeNull();
});

it('refuses to describe the same product twice, once per section', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('<html><body>Acme.</body></html>', 200, ['Content-Type' => 'text/html'])]);
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'offerings' => [
            ['name' => 'YuhuDILS', 'description' => 'Interest-free marketplace.'],
            ['name' => 'YUPI', 'description' => 'An AI coach.'],
        ],
        'glossary' => [
            ['term' => 'yuhudils', 'meaning' => 'Interest-free marketplace.'],
            ['term' => 'YUPI', 'meaning' => 'An AI coach.'],
            ['term' => 'colaborador', 'meaning' => 'How the company refers to an employee.'],
        ],
    ])]);

    $draft = app(ContextProposalService::class)->propose('https://acme.example', '', $this->owner);

    // The offering keeps the product; only the term that carries a real
    // organization-specific meaning survives in the glossary.
    expect($draft['profile']['offerings'])->toHaveCount(2)
        ->and($draft['profile']['glossary'])->toBe([
            ['term' => 'colaborador', 'meaning' => 'How the company refers to an employee.'],
        ]);
});

it('never lists the home page several times under invented labels', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('<html><body>Acme.</body></html>', 200, ['Content-Type' => 'text/html'])]);
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'links' => [
            ['label' => 'White paper', 'url' => 'https://acme.example'],
            ['label' => 'Product A', 'url' => 'https://acme.example/'],
            ['label' => 'Product B', 'url' => 'http://acme.example'],
            ['label' => 'Status', 'url' => 'https://status.acme.example'],
        ],
    ])]);

    $draft = app(ContextProposalService::class)->propose('https://acme.example', '', $this->owner);

    // Three spellings of the home page collapse, and the home page itself drops
    // out once there is a real destination to keep.
    expect($draft['profile']['links'])->toBe([
        ['label' => 'Status', 'url' => 'https://status.acme.example'],
    ]);
});

it('keeps the home page when it is the only link there is', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('<html><body>Acme.</body></html>', 200, ['Content-Type' => 'text/html'])]);
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'links' => [['label' => 'Site', 'url' => 'https://acme.example']],
    ])]);

    expect(app(ContextProposalService::class)->propose('https://acme.example', '', $this->owner)['profile']['links'])
        ->toBe([['label' => 'Site', 'url' => 'https://acme.example']]);
});

it('says so plainly when there is nothing to work from', function () {
    $draft = app(ContextProposalService::class)->propose(null, '', $this->owner);

    expect($draft['generated'])->toBeFalse()
        ->and($draft['sources'])->toBe([])
        ->and($draft['profile']['descriptor'])->toBeNull();
});

it('degrades to the brief alone when the website cannot be read', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('nope', 500)]);
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'From the brief only.'])]);

    $draft = app(ContextProposalService::class)->propose('https://acme.example', 'Freight company', $this->owner);

    expect($draft['sources'])->toBe(['brief'])
        ->and($draft['profile']['descriptor'])->toBe('From the brief only.');
});

/**
 * The rule both books share: a draft never replaces what a human wrote. It
 * reports the clash and leaves the stored value exactly where it was.
 */
it('never overwrites a contextbook that is already written — it reports the clash', function () {
    config()->set('security.ssrf.enabled', false);
    Http::fake(['*' => Http::response('<html><body>Acme.</body></html>', 200, ['Content-Type' => 'text/html'])]);
    Ai::fakeAgent(ChatAgent::class, [json_encode([
        'descriptor' => 'What the website says.',
        'industry' => 'logistics',
    ])]);

    $stored = OrganizationAiContext::firstOrNew(['organization_id' => $this->org->id])
        ->setRelation('organization', $this->org);
    $stored->fill(['profile' => ['descriptor' => 'What we wrote ourselves.']])->recompile()->save();

    $draft = app(ContextProposalService::class)
        ->propose('https://acme.example', '', $this->owner, $stored->profile);

    $byField = collect($draft['diff'])->keyBy('field');

    expect($draft['has_conflicts'])->toBeTrue()
        ->and($byField['descriptor']['status'])->toBe(DraftDiff::CONFLICT)
        ->and($byField['descriptor']['current'])->toBe('What we wrote ourselves.')
        // An untouched field is still free to fill without asking.
        ->and($byField['industry']['status'])->toBe(DraftDiff::NEW);

    // And the stored Contextbook is untouched, block included.
    expect($stored->fresh()->profile['descriptor'])->toBe('What we wrote ourselves.')
        ->and($stored->fresh()->compiled_prompt)->toContain('What we wrote ourselves.');
});

it('exposes the draft over the settings endpoint without storing it', function () {
    config()->set('security.ssrf.enabled', false); // the guard has its own tests; here we exercise the fetch
    Http::fake(['*' => Http::response(
        '<html><body>Acme moves refrigerated freight.</body></html>',
        200,
        ['Content-Type' => 'text/html'],
    )]);
    Ai::fakeAgent(ChatAgent::class, [json_encode(['descriptor' => 'Moves refrigerated freight.'])]);

    $this->actingAs($this->owner)
        ->postJson('/settings/organization/context/draft', ['website' => 'https://acme.example'])
        ->assertOk()
        ->assertJsonPath('generated', true)
        ->assertJsonPath('profile.descriptor', 'Moves refrigerated freight.')
        ->assertJsonStructure(['profile', 'sources', 'preview', 'tokens']);

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});
