<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\GetBuildQualityTool;
use App\Models\App;
use App\Models\BuildFinding;
use App\Models\User;
use App\Services\Builder\BuildFindingLedger;

/**
 * The read side of the build-failure ledger, and the counterpart to
 * get_build_cost: that one says what a build cost, this one says what went
 * wrong in it. Its whole reason to exist is the org-wide view — a recurring
 * defect is invisible inside any single build.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'quality_target',
    ]);
});

it('ranks what goes wrong across every build in the organization', function () {
    $other = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'quality_other',
    ]);

    $ledger = app(BuildFindingLedger::class);
    $ledger->record($this->testApp->id, BuildFinding::SIGNAL_PATCH_REJECTED, [
        ['code' => 'page_index_mismatch', 'path' => '/pages/0', 'at' => '/pages/0 is the page `refacciones_detail`', 'detail' => 'wrong page'],
    ], 'cnv_a', 'anthropic/claude-haiku-latest');
    $ledger->record($other->id, BuildFinding::SIGNAL_PATCH_REJECTED, [
        ['code' => 'page_index_mismatch', 'detail' => 'wrong page again'],
    ], 'cnv_b', 'anthropic/claude-haiku-latest');
    $ledger->record($other->id, BuildFinding::SIGNAL_CRITIC, [
        ['code' => BuildFinding::CODE_UNREQUESTED, 'detail' => 'una página «Punto de venta»'],
    ], 'cnv_b', 'anthropic/claude-haiku-latest');

    SapiensServer::actingAs($this->user)
        ->tool(GetBuildQualityTool::class, [])
        ->assertOk()
        // The pattern only shows up because both apps are counted together.
        ->assertSee('page_index_mismatch')
        ->assertSee('"count":2')
        ->assertSee('Punto de venta')
        ->assertSee('refacciones_detail')
        ->assertSee('"scope":"organization"');
});

it('scopes to one app when asked', function () {
    $ledger = app(BuildFindingLedger::class);
    $ledger->record($this->testApp->id, BuildFinding::SIGNAL_CRITIC, [
        ['code' => BuildFinding::CODE_MISSING, 'detail' => 'la firma es un campo de texto'],
    ]);
    $ledger->record('app_elsewhere0001', BuildFinding::SIGNAL_CRITIC, [
        ['code' => BuildFinding::CODE_MISSING, 'detail' => 'algo de otra app'],
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(GetBuildQualityTool::class, ['app_slug' => 'quality_target'])
        ->assertOk()
        ->assertSee('la firma es un campo de texto')
        ->assertDontSee('algo de otra app');
});

it('says so plainly when there is nothing recorded', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetBuildQualityTool::class, ['app_slug' => 'quality_target'])
        ->assertOk()
        ->assertSee('No findings recorded');
});

it('refuses an app it cannot see', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetBuildQualityTool::class, ['app_slug' => 'not_yours'])
        ->assertSee('is visible to you');
});
