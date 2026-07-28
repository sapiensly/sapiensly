<?php

use App\Models\AiUsageEvent;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * The spend chart was a shape with no numbers on it: no value scale, no time
 * scale, and no way to ask a point what it was worth.
 */
function spendOnDay(string $organizationId, int $userId, string $at, float $cost): void
{
    // created_at is not fillable, so the clock is what sets it.
    test()->travelTo($at);
    AiUsageEvent::create([
        'organization_id' => $organizationId,
        'user_id' => $userId,
        'module' => 'chat',
        'driver' => 'anthropic',
        'model' => 'claude-test',
        'source' => 'system',
        'input_tokens' => 1000,
        'output_tokens' => 500,
        'cost' => $cost,
    ]);
}

it('scales both axes of the spend chart to the data', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // A week with a clear peak, so both axes have something to say.
    foreach ([
        ['2026-07-22 12:00:00', 0.02],
        ['2026-07-23 12:00:00', 0.31],
        ['2026-07-24 12:00:00', 0.05],
        ['2026-07-26 12:00:00', 0.44],
        ['2026-07-27 12:00:00', 0.12],
    ] as [$at, $cost]) {
        spendOnDay($org->id, $user->id, $at, $cost);
    }
    $this->travelTo('2026-07-28 12:00:00');

    visit('/system/ai-spend?period=7d')
        ->assertSee('Daily spend')
        // The y axis rounds up to a readable ceiling above the $0.44 peak…
        ->assertSee('$0.60')
        ->assertSee('$0.30')
        // …and the x axis names the ends of the window.
        ->assertSee('Jul 22')
        ->assertSee('Jul 28')
        ->assertNoJavaScriptErrors();
});

it('reads out a data point on hover', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $user = mcpMember($org = mcpOrg());
    $this->actingAs($user);

    // One spike in the middle of the window, which is where hovering the plot
    // puts the pointer.
    spendOnDay($org->id, $user->id, '2026-07-25 12:00:00', 0.44);
    $this->travelTo('2026-07-28 12:00:00');

    visit('/system/ai-spend?period=7d')
        // The 25th is not an axis tick, so seeing it proves the readout.
        ->assertDontSee('Jul 25')
        ->hover('[data-sp-chart-plot]')
        ->assertSee('Jul 25')
        ->assertSee('$0.44')
        ->assertNoJavaScriptErrors();
});
