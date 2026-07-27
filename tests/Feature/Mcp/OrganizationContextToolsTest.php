<?php

use App\Ai\Tools\Platform\PlatformToolsFactory;
use App\Enums\MembershipRole;
use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Account\GetOrganizationContextTool;
use App\Mcp\Tools\Account\SetOrganizationContextTool;
use App\Models\OrganizationAiContext;
use App\Support\Context\OrganizationContext;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The Contextbook MCP tools, mirroring the Brandbook pair: any member reads the
 * context (to write as the organization); only an owner/sysadmin sets it.
 */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $this->org = mcpOrg();
    $this->owner = mcpMember($this->org, MembershipRole::Owner);
    $this->member = mcpMember($this->org, MembershipRole::Member);
});

it('reads the organization context', function () {
    OrganizationAiContext::firstOrNew(['organization_id' => $this->org->id])
        ->setRelation('organization', $this->org)
        ->fill(['profile' => OrganizationContext::fromArray([
            'descriptor' => 'Moves refrigerated freight.',
            'glossary' => [['term' => 'guia', 'meaning' => 'the shipment document']],
        ])->toArray()])
        ->recompile()
        ->save();

    SapiensServer::actingAs($this->member)
        ->tool(GetOrganizationContextTool::class, [])
        ->assertOk()
        ->assertSee('Moves refrigerated freight.')
        ->assertSee('the shipment document')
        ->assertSee('prompt_block');
});

it('tells the caller when no context is set yet', function () {
    SapiensServer::actingAs($this->member)
        ->tool(GetOrganizationContextTool::class, [])
        ->assertOk()
        ->assertSee('Contextbook unset');
});

it('sets the context and compiles the block', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(SetOrganizationContextTool::class, [
            'descriptor' => 'Moves refrigerated freight.',
            'currency' => 'mxn',
            'never' => ['Quote prices'],
        ])
        ->assertOk()
        ->assertSee('updated');

    $row = OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail();

    expect($row->profile['currency'])->toBe('MXN')
        ->and($row->compiled_prompt)->toContain('- Quote prices')
        ->and($row->updated_by_id)->toBe($this->owner->id);
});

it('merges a partial set over the stored context', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(SetOrganizationContextTool::class, ['descriptor' => 'First.', 'industry' => 'logistics'])
        ->assertOk();

    SapiensServer::actingAs($this->owner)
        ->tool(SetOrganizationContextTool::class, ['descriptor' => 'Second.'])
        ->assertOk();

    $row = OrganizationAiContext::where('organization_id', $this->org->id)->firstOrFail();

    expect($row->profile['descriptor'])->toBe('Second.')
        ->and($row->profile['industry'])->toBe('logistics');
});

it('refuses a context that would blow the per-call token budget', function () {
    SapiensServer::actingAs($this->owner)
        ->tool(SetOrganizationContextTool::class, [
            'glossary' => array_fill(0, 20, ['term' => str_repeat('t', 40), 'meaning' => str_repeat('m', 160)]),
            'offerings' => array_fill(0, 10, ['name' => str_repeat('n', 60), 'description' => str_repeat('d', 160)]),
            'never' => array_fill(0, 10, str_repeat('x', 160)),
        ])
        ->assertHasErrors();

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('lets only an owner or sysadmin set the context', function () {
    SapiensServer::actingAs($this->member)
        ->tool(SetOrganizationContextTool::class, ['descriptor' => 'Sneaky.'])
        ->assertHasErrors();

    expect(OrganizationAiContext::where('organization_id', $this->org->id)->exists())->toBeFalse();
});

it('never lets a model edit the contextbook on its own initiative', function () {
    // It lands in the system prompt of every agent in the organization, so the
    // write goes through the confirm card, never straight from a model turn.
    expect(SapiensServer::TOOLS)->toContain(SetOrganizationContextTool::class)
        ->and(PlatformToolsFactory::CONFIRM_REQUIRED)->toContain('set_organization_context')
        ->and(PlatformToolsFactory::CONFIRM_REQUIRED)->not->toContain('get_organization_context');
});
