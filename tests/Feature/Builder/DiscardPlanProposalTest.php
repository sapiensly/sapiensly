<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;

/**
 * Discarding a plan proposal must be DETERMINISTIC: the card goes inert and
 * the build-plan steps the proposal targeted are skipped, so the autonomous
 * loop can never build the very thing the user just rejected (before this,
 * "discard" was only a chat message and the step stayed pending).
 */
beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->testApp = App::factory()->create(['user_id' => $this->user->id, 'visibility' => 'private']);
    $this->conv = BuilderConversation::create([
        'app_id' => $this->testApp->id,
        'user_id' => $this->user->id,
        'status' => 'active',
        'build_plan' => ['schema' => 1, 'goal' => null, 'status' => 'active', 'steps' => [
            ['id' => 'stp_done', 'title' => 'Landing', 'detail' => null, 'status' => 'done', 'applied_version_id' => 'apv_1', 'version_number' => 1, 'closed_by_summary' => 'ok', 'error' => null],
            ['id' => 'stp_wf', 'title' => 'Workflow bienvenida', 'detail' => null, 'status' => 'pending', 'applied_version_id' => null, 'version_number' => null, 'closed_by_summary' => null, 'error' => null],
        ]],
    ]);
    $this->proposal = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => 'Plan propuesto',
        'status' => 'none',
        'plan' => ['summary' => 'Email de bienvenida al capturar un lead', 'trigger' => 'record.created en Leads', 'steps' => []],
        'plan_step_ids' => ['stp_wf'],
    ]);
});

it('stamps the card discarded and skips the targeted build-plan steps', function () {
    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages/{$this->proposal->id}/discard-plan")
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('skipped_step_ids.0', 'stp_wf');

    expect($this->proposal->refresh()->plan['status'])->toBe('discarded');

    $plan = $this->conv->refresh()->build_plan;
    $byId = collect($plan['steps'])->keyBy('id');
    expect($byId['stp_wf']['status'])->toBe('skipped')
        ->and($byId['stp_done']['status'])->toBe('done')
        // Nothing open remains → the plan closes, so autonomous mode stops.
        ->and($plan['status'])->toBe('done');
});

it('422s on a message that carries no plan proposal', function () {
    $chat = BuilderMessage::create([
        'conversation_id' => $this->conv->id, 'role' => 'assistant', 'content' => 'hola', 'status' => 'none',
    ]);

    $this->actingAs($this->user)
        ->postJson("/apps/{$this->testApp->id}/builder/messages/{$chat->id}/discard-plan")
        ->assertStatus(422);
});

it('rejects a stranger', function () {
    $stranger = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($stranger)
        ->postJson("/apps/{$this->testApp->id}/builder/messages/{$this->proposal->id}/discard-plan")
        ->assertStatus(403);
});
