<?php

use App\Services\Builder\BuildPlan;

it('creates a plan with minted ids, all pending, status active', function () {
    $plan = BuildPlan::reconcile(null, 'POS', [
        ['title' => 'Crear objetos'],
        ['title' => 'Páginas', 'detail' => 'CRUD por objeto'],
    ]);

    expect($plan['schema'])->toBe(BuildPlan::SCHEMA)
        ->and($plan['goal'])->toBe('POS')
        ->and($plan['status'])->toBe('active')
        ->and($plan['steps'])->toHaveCount(2)
        ->and($plan['steps'][0]['id'])->toStartWith('stp_')
        ->and($plan['steps'][0]['status'])->toBe('pending')
        ->and($plan['steps'][1]['detail'])->toBe('CRUD por objeto');
});

it('reconcile preserves status of matching ids, skips dropped non-done, keeps dropped done', function () {
    $base = BuildPlan::reconcile(null, null, [
        ['title' => 'A'],
        ['title' => 'B'],
        ['title' => 'C'],
    ]);
    $idA = $base['steps'][0]['id'];
    $idB = $base['steps'][1]['id'];

    // A is done, B is pending; now the model resubmits only A (by id) + a new D.
    $base = BuildPlan::closeApplied(BuildPlan::markInProgress($base, [$idA]), 'apv_x', 3, 'hecho A');

    $next = BuildPlan::reconcile($base, null, [
        ['id' => $idA, 'title' => 'A (renombrada)'],
        ['title' => 'D'],
    ]);

    $byId = collect($next['steps'])->keyBy('id');
    // A kept its done status + version stamp, text updated.
    expect($byId[$idA]['status'])->toBe('done')
        ->and($byId[$idA]['version_number'])->toBe(3)
        ->and($byId[$idA]['title'])->toBe('A (renombrada)');
    // B was dropped and was not done → skipped (preserved, not deleted).
    expect($byId[$idB]['status'])->toBe('skipped');
    // D is new + pending.
    expect(collect($next['steps'])->firstWhere('title', 'D')['status'])->toBe('pending');
});

it('markInProgress only flips pending/failed steps and ignores unknown ids', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A'], ['title' => 'B']]);
    $idA = $plan['steps'][0]['id'];
    $plan = BuildPlan::closeApplied(BuildPlan::markInProgress($plan, [$idA]), 'apv_x', 1, null);

    // Try to target the already-done A plus an unknown id → no effect.
    $plan = BuildPlan::markInProgress($plan, [$idA, 'stp_unknown']);
    expect($plan['steps'][0]['status'])->toBe('done')
        ->and(BuildPlan::inProgressIds($plan))->toBe([]);
});

it('closeApplied closes in_progress steps and derives done when none remain open', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'only']]);
    $plan = BuildPlan::markInProgress($plan, [$plan['steps'][0]['id']]);

    $plan = BuildPlan::closeApplied($plan, 'apv_42', 7, 'listo');

    expect($plan['steps'][0]['status'])->toBe('done')
        ->and($plan['steps'][0]['applied_version_id'])->toBe('apv_42')
        ->and($plan['steps'][0]['version_number'])->toBe(7)
        ->and($plan['steps'][0]['closed_by_summary'])->toBe('listo')
        ->and($plan['status'])->toBe('done');
});

it('failInProgress and resetInProgress move targeted steps without closing them', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A'], ['title' => 'B']]);
    $idA = $plan['steps'][0]['id'];
    $idB = $plan['steps'][1]['id'];

    $failed = BuildPlan::failInProgress(BuildPlan::markInProgress($plan, [$idA]), 'boom');
    expect($failed['steps'][0]['status'])->toBe('failed')
        ->and($failed['steps'][0]['error'])->toBe('boom')
        ->and($failed['status'])->toBe('active');

    // A phantom turn (targeted but proposed nothing) resets to pending.
    $reset = BuildPlan::resetInProgress(BuildPlan::markInProgress($plan, [$idB]));
    expect($reset['steps'][1]['status'])->toBe('pending');
});

it('reopenForVersion reopens steps closed by a reverted version only', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A'], ['title' => 'B']]);
    $idA = $plan['steps'][0]['id'];
    $idB = $plan['steps'][1]['id'];
    $plan = BuildPlan::closeApplied(BuildPlan::markInProgress($plan, [$idA]), 'apv_1', 1, 'a');
    $plan = BuildPlan::closeApplied(BuildPlan::markInProgress($plan, [$idB]), 'apv_2', 2, 'b');

    $plan = BuildPlan::reopenForVersion($plan, 'apv_1');

    $byId = collect($plan['steps'])->keyBy('id');
    expect($byId[$idA]['status'])->toBe('pending')
        ->and($byId[$idA]['applied_version_id'])->toBeNull()
        ->and($byId[$idA]['version_number'])->toBeNull()
        // B (closed by a different version) is untouched.
        ->and($byId[$idB]['status'])->toBe('done');
});

it('never recomputes an abandoned plan back to active', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A']]);
    $plan['status'] = 'abandoned';

    $plan = BuildPlan::markInProgress($plan, [$plan['steps'][0]['id']]);

    expect($plan['status'])->toBe('abandoned');
});

it('closeApplied falls back to the first open step when nothing was targeted', function () {
    // The exact production trace: T1 targeted step A but its version applied
    // via the timeout checkpoint (which used to skip bookkeeping); the resumed
    // T2 skipped target_plan_steps and applied the dashboard. The apply must
    // advance the plan anyway — first open step, in order.
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A'], ['title' => 'B']]);
    $idA = $plan['steps'][0]['id'];
    $idB = $plan['steps'][1]['id'];

    // No step in_progress → the version closes A (first pending).
    $plan = BuildPlan::closeApplied($plan, 'apv_1', 2, 'obj');
    expect(collect($plan['steps'])->keyBy('id')[$idA]['status'])->toBe('done')
        ->and(collect($plan['steps'])->keyBy('id')[$idA]['version_number'])->toBe(2)
        ->and(collect($plan['steps'])->keyBy('id')[$idB]['status'])->toBe('pending')
        ->and($plan['status'])->toBe('active');

    // Next untargeted apply closes B → plan done.
    $plan = BuildPlan::closeApplied($plan, 'apv_2', 3, 'dash');
    expect(collect($plan['steps'])->keyBy('id')[$idB]['status'])->toBe('done')
        ->and($plan['status'])->toBe('done');
});

it('closeApplied prefers the targeted step over the fallback', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A'], ['title' => 'B']]);
    $idB = $plan['steps'][1]['id'];

    // B is explicitly in_progress → the version closes B, NOT first-pending A.
    $plan = BuildPlan::closeApplied(BuildPlan::markInProgress($plan, [$idB]), 'apv_9', 5, 'hecho B');
    expect($plan['steps'][0]['status'])->toBe('pending')
        ->and($plan['steps'][1]['status'])->toBe('done');
});

it('skip marks rejected steps skipped, never touches done, and can complete the plan', function () {
    $plan = BuildPlan::reconcile(null, null, [['title' => 'A'], ['title' => 'B']]);
    $idA = $plan['steps'][0]['id'];
    $idB = $plan['steps'][1]['id'];
    $plan = BuildPlan::closeApplied(BuildPlan::markInProgress($plan, [$idA]), 'apv_x', 1, null);

    // Skipping the done A is a no-op; skipping pending B closes the plan.
    $plan = BuildPlan::skip($plan, [$idA, $idB, 'stp_unknown']);

    expect($plan['steps'][0]['status'])->toBe('done')
        ->and($plan['steps'][1]['status'])->toBe('skipped')
        ->and($plan['status'])->toBe('done');
});
