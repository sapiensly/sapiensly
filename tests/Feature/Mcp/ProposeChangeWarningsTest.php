<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\ProposeChangeTool;
use App\Models\App;
use App\Models\User;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\AppScaffolder;
use Illuminate\Support\Str;

/**
 * The design lint on the path an EXTERNAL agent builds through.
 *
 * The builder's own propose_change has returned these warnings since the rules
 * were written. This one returned them only when the patch was REJECTED, so a
 * patch that applied WITH warnings came back as a bare `{"applied":true}`.
 *
 * Measured on a live app built over MCP: an «Abrir» button pointing at a page
 * that does not exist, and an object listed with no way to open one of its
 * records. Both warnings existed, both were reachable through `audit_app`, and
 * the caller had no reason to suspect it needed to ask.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->appModel = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'content_engine',
    ]);

    $manifests = app(AppManifestService::class);
    $manifest = app(AppScaffolder::class)->assemble($manifests->initialManifest($this->appModel), [
        'objects' => [
            ['name' => 'Ideas', 'slug' => 'ideas', 'fields' => [
                ['name' => 'Title', 'slug' => 'title', 'type' => 'string', 'options' => null],
            ]],
        ],
    ]);
    $manifests->createVersion($this->appModel, $manifest, $this->user, 'seed');
    $this->manifest = $manifest;
});

it('propose_change returns the design warnings of a patch that APPLIED', function () {
    // Aim an existing control at a page nothing serves (R12). The patch is
    // valid — a manifest may hold a broken link — so it applies.
    $ops = [[
        'op' => 'add',
        'path' => '/pages/0/blocks/-',
        'value' => [
            'id' => 'blk_'.strtolower((string) Str::ulid()),
            'type' => 'button',
            'label' => 'Abrir',
            'on_click' => [['type' => 'navigate', 'to' => '/ideas_detail_2']],
        ],
    ]];

    $response = SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, ['app_slug' => 'content_engine', 'ops' => $ops]);

    $response->assertOk()
        ->assertSee('applied')
        ->assertSee('design_smell')
        ->assertSee('ideas_detail_2')
        // The instruction, not just the data: a bare list of warnings is easy
        // to apply and then report success over.
        ->assertSee('Do NOT report success');
});

it('propose_change stays quiet when the patch applied cleanly', function () {
    $ops = [[
        'op' => 'replace',
        'path' => '/name',
        'value' => 'Content Engine II',
    ]];

    $response = SapiensServer::actingAs($this->user)
        ->tool(ProposeChangeTool::class, ['app_slug' => 'content_engine', 'ops' => $ops]);

    $response->assertOk()
        ->assertSee('applied')
        ->assertDontSee('design_smell')
        ->assertDontSee('Do NOT report success');
});
