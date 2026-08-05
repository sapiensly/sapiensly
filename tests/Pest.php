<?php

use App\Enums\AgentStatus;
use App\Enums\ChatbotStatus;
use App\Enums\DocumentType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\ToolType;
use App\Enums\Visibility;
use App\Mcp\McpContext;
use App\Models\Agent;
use App\Models\App;
use App\Models\Channel;
use App\Models\Chat;
use App\Models\Chatbot;
use App\Models\Document;
use App\Models\Integration;
use App\Models\IntegrationExecution;
use App\Models\KnowledgeBase;
use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Tool;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConversation;
use App\Services\AiProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
 * Real-browser tests. Tagged `browser` so the default suite skips them —
 * they need a Playwright browser and a served app, which not every machine
 * or CI lane has. Run them explicitly with `--group=browser`.
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->group('browser')
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
 * Shared MCP test helpers (org-bound access), used across tests/Feature/Mcp and
 * tests/Feature/System.
 */
function mcpOrg(string $name = 'Acme'): Organization
{
    return Organization::create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
    ]);
}

function mcpMember(
    Organization $org,
    MembershipRole $role = MembershipRole::Owner,
    ?Organization $activeOrg = null,
): User {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'organization_id' => ($activeOrg ?? $org)->id,
    ]);
    OrganizationMembership::create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => MembershipStatus::Active,
    ]);

    return $user;
}

/**
 * A member who also holds the PLATFORM sysadmin role. The role is assigned with
 * a null spatie team, exactly as SysAdminSeeder does — assigning it under the
 * org's team would produce a user who looks like a sysadmin to hasRole() and
 * isn't one, which is the opposite of what these tests need to prove.
 */
function mcpSysadmin(Organization $org, ?Organization $activeOrg = null): User
{
    $user = mcpMember($org, MembershipRole::Owner, $activeOrg);

    $previousTeam = getPermissionsTeamId();
    setPermissionsTeamId(null);
    $user->assignRole(User::SYSADMIN_ROLE);
    setPermissionsTeamId($previousTeam);
    $user->unsetRelation('roles');

    return $user;
}

/**
 * Bind an McpContext carrying a token with these abilities, the way
 * AuthenticateMcpToken does per request. Platform tools fail closed without
 * one, so a test that omits this is testing the gate, not the tool.
 *
 * @param  list<string>  $abilities
 */
function mcpActingContext(array $abilities): McpAccessToken
{
    $token = new McpAccessToken(['abilities' => $abilities]);
    app()->instance(McpContext::class, new McpContext($token));

    return $token;
}

/**
 * A UI label as the browser will actually render it. The pages render in the
 * request locale, so a test that clicks a hard-coded English string passes or
 * fails on the machine's locale rather than on the behaviour under test.
 */
function builderLabel(string $key): string
{
    $file = resource_path('js/locales/'.app()->getLocale().'.json');
    $messages = json_decode(
        (string) file_get_contents(file_exists($file) ? $file : resource_path('js/locales/en.json')),
        true,
    );

    return $messages[$key] ?? $key;
}

/**
 * A chat model id the catalog actually contains.
 *
 * Agent writes and provider probes validate the model against the catalog, so
 * a test that spells an id out starts failing the day the provider retires it
 * — which is how ten files came to pin one dead string, and why refreshing the
 * catalog looked like a ten-file change. Ask for the Nth chat model of a driver
 * and the fixtures follow the catalog on their own.
 */
function catalogChatModel(int $index = 0, string $driver = 'anthropic'): string
{
    return AiProviderService::bootstrapChatModelId($driver, $index)
        ?? throw new OutOfRangeException("No chat model at index {$index} in the {$driver} catalog.");
}

function mcpToken(Organization $org, User $user, array $attrs = []): string
{
    $plain = McpAccessToken::generateToken();
    McpAccessToken::create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'cc',
        'token' => $plain,
    ], $attrs));

    return $plain;
}

/**
 * Fills a tenant with enough content that the list screens render ROWS rather
 * than their empty state.
 *
 * Written for the responsive browser sweep, where an empty tenant makes every
 * check vacuous: an empty state is responsive by construction, so a green
 * sweep over one proves nothing about the screen with data in it.
 *
 * Names are deliberately long and realistic. Short `fake()->words(2)` names
 * fit anywhere; what actually breaks a 390px layout is a title that wants
 * more room than the column has.
 *
 * Returns one representative of each type so the detail-screen sweep can
 * build its URLs.
 *
 * @return array<string, mixed>
 */
function seedTenantContent(Organization $org, User $user): array
{
    $owned = ['user_id' => $user->id, 'organization_id' => $org->id, 'visibility' => Visibility::Organization];

    $longNames = [
        'Customer Onboarding & Verification Workflow (EMEA)',
        'Refund Eligibility Checker — Tier 2 Escalations',
        'Warehouse Inventory Reconciliation Assistant',
    ];

    $created = [];

    foreach ($longNames as $name) {
        $created['agent'] = Agent::factory()->create($owned + ['name' => $name, 'status' => AgentStatus::Active]);
        $created['tool'] = Tool::factory()->create($owned + ['name' => $name, 'type' => ToolType::RestApi]);
        $created['chatbot'] = Chatbot::factory()->create($owned + ['name' => $name, 'status' => ChatbotStatus::Active]);
        $created['knowledgeBase'] = KnowledgeBase::factory()->create($owned + ['name' => $name]);
        $created['integration'] = Integration::factory()->create($owned + ['name' => $name]);
        $created['app'] = App::factory()->create($owned + ['name' => $name]);
        $created['chat'] = Chat::factory()->create($owned + ['title' => $name, 'last_message_at' => now()]);

        $created['document'] = Document::create($owned + [
            'name' => $name.'.pdf',
            'type' => DocumentType::Pdf,
            'original_filename' => $name.'.pdf',
            'file_path' => 'documents/'.Str::random(12).'.pdf',
            'file_size' => 2_400_000,
        ]);

        // Executions hang off an integration and drive its history screen.
        IntegrationExecution::factory()->create([
            'integration_id' => $created['integration']->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'url' => 'https://api.example.com/v1/'.Str::slug($name).'/reconciliation-batches',
        ]);
    }

    // WhatsApp hangs off a Channel, so it is built once rather than per name.
    $channel = Channel::factory()->whatsapp()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'visibility' => Visibility::Organization,
        'name' => 'EMEA Customer Support — WhatsApp Business',
    ]);
    $created['whatsappConnection'] = WhatsAppConnection::factory()->create([
        'channel_id' => $channel->id,
    ]);
    $created['whatsappConversation'] = WhatsAppConversation::factory()->create([
        'channel_id' => $channel->id,
        'title' => 'Refund request — order 48812-EMEA',
    ]);

    return $created;
}

/** @return array<string, mixed> */
function mcpToolsList(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
}

/**
 * blockData is a DEFERRED Inertia prop: the shell responds without it and the
 * client fetches it in a follow-up partial request — which this replicates.
 */
function deferredBlockData($test, string $url, string $component = 'runtime/Page', string $props = 'blockData')
{
    $shell = $test->get($url);
    $version = (string) ($shell->original->getData()['page']['version'] ?? '');

    return $test->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $props,
    ]);
}

/**
 * Body copy for a faked home page. Long enough to clear the prose floor
 * SiteProfile::hasProse() applies — a one-liner is not what a real home page
 * looks like, and the floor exists to tell a page with words from one without.
 */
function siteCopy(): string
{
    return 'Acme moves refrigerated freight for food producers across the country. '
        .'We run temperature-controlled trailers, same-day dispatch and a live tracking portal '
        .'so shippers always know where a load is. Founded in 2009, based in Monterrey.';
}
