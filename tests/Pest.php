<?php

use App\Enums\AgentStatus;
use App\Enums\ChatbotStatus;
use App\Enums\DocumentType;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Enums\ToolType;
use App\Enums\Visibility;
use App\Models\Agent;
use App\Models\App;
use App\Models\Chat;
use App\Models\Chatbot;
use App\Models\Document;
use App\Models\Integration;
use App\Models\KnowledgeBase;
use App\Models\McpAccessToken;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Tool;
use App\Models\User;
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
 */
function seedTenantContent(Organization $org, User $user): void
{
    $owned = ['user_id' => $user->id, 'organization_id' => $org->id, 'visibility' => Visibility::Organization];

    $longNames = [
        'Customer Onboarding & Verification Workflow (EMEA)',
        'Refund Eligibility Checker — Tier 2 Escalations',
        'Warehouse Inventory Reconciliation Assistant',
    ];

    foreach ($longNames as $name) {
        Agent::factory()->create($owned + ['name' => $name, 'status' => AgentStatus::Active]);
        Tool::factory()->create($owned + ['name' => $name, 'type' => ToolType::RestApi]);
        Chatbot::factory()->create($owned + ['name' => $name, 'status' => ChatbotStatus::Active]);
        KnowledgeBase::factory()->create($owned + ['name' => $name]);
        Integration::factory()->create($owned + ['name' => $name]);
        App::factory()->create($owned + ['name' => $name]);
        Chat::factory()->create($owned + ['title' => $name, 'last_message_at' => now()]);

        Document::create($owned + [
            'name' => $name.'.pdf',
            'type' => DocumentType::Pdf,
            'original_filename' => $name.'.pdf',
            'file_path' => 'documents/'.Str::random(12).'.pdf',
            'file_size' => 2_400_000,
        ]);
    }
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
