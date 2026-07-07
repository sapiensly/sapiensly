<?php

use App\Mcp\Servers\SapiensServer;
use App\Mcp\Tools\Build\GetBuildCostTool;
use App\Models\AiUsageEvent;
use App\Models\App;
use App\Models\User;

/**
 * Per-build cost attribution over MCP: sums the AI usage events tagged with an
 * app_id (and optionally one conversation) into total/per-model/per-conversation
 * cost — the query that used to require approximating from weekly per-model
 * totals.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create([
        'user_id' => $this->user->id,
        'organization_id' => $this->user->organization_id,
        'slug' => 'cost_target',
    ]);
});

function costEvent(string $appId, array $attrs): AiUsageEvent
{
    return AiUsageEvent::create(array_merge([
        'organization_id' => 'org_costtest0001',
        'module' => 'builder',
        'app_id' => $appId,
        'driver' => 'anthropic',
        'model' => 'claude-fable-5',
        'source' => 'system',
        'input_tokens' => 1000,
        'output_tokens' => 100,
        'cost' => 1.0,
        'status' => 'success',
    ], $attrs));
}

it('totals a build cost with per-model and per-conversation splits', function () {
    costEvent($this->testApp->id, ['model' => 'claude-fable-5', 'conversation_id' => 'cnv_build_a', 'cost' => 1.06, 'input_tokens' => 104000]);
    costEvent($this->testApp->id, ['model' => 'z-ai/glm-5v-turbo', 'conversation_id' => 'cnv_build_b', 'cost' => 0.20, 'input_tokens' => 146000, 'module' => 'express']);
    // Noise: a different app's cost must not leak in.
    costEvent('app_other00000000', ['cost' => 9.99]);

    SapiensServer::actingAs($this->user)
        ->tool(GetBuildCostTool::class, ['app_slug' => 'cost_target'])
        ->assertOk()
        ->assertSee('"cost":1.26')          // 1.06 + 0.20, NOT the other app's 9.99
        ->assertSee('claude-fable-5')
        ->assertSee('z-ai/glm-5v-turbo')
        ->assertSee('cnv_build_a')
        ->assertSee('Express');             // module 'express' → its own service line
});

it('scopes to a single conversation when given one', function () {
    costEvent($this->testApp->id, ['conversation_id' => 'cnv_build_a', 'cost' => 1.06]);
    costEvent($this->testApp->id, ['conversation_id' => 'cnv_build_b', 'cost' => 0.20]);

    SapiensServer::actingAs($this->user)
        ->tool(GetBuildCostTool::class, ['app_slug' => 'cost_target', 'conversation_id' => 'cnv_build_a'])
        ->assertOk()
        ->assertSee('"cost":1.06')
        ->assertSee('cnv_build_a');
});

it('rejects an app the caller cannot see', function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    App::factory()->create([
        'user_id' => $other->id,
        'organization_id' => $other->organization_id,
        'slug' => 'someone_elses',
    ]);

    SapiensServer::actingAs($this->user)
        ->tool(GetBuildCostTool::class, ['app_slug' => 'someone_elses'])
        ->assertSee('is visible to you');
});

it('notes when a build has no tagged calls yet', function () {
    SapiensServer::actingAs($this->user)
        ->tool(GetBuildCostTool::class, ['app_slug' => 'cost_target'])
        ->assertOk()
        ->assertSee('unattributed');
});
