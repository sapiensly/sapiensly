<?php

use App\Models\Integration;
use App\Models\IntegrationExecution;
use App\Models\User;

test('pruning keeps the last N executions per integration', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($user)->create();

    config(['integrations.execution_retention.count' => 3]);
    config(['integrations.execution_retention.days' => 0]);

    // Create 6 executions, all old enough to be prunable.
    for ($i = 0; $i < 6; $i++) {
        IntegrationExecution::factory()
            ->forIntegration($integration)
            ->create(['created_at' => now()->subDays(60)->addMinutes($i)]);
    }

    expect(IntegrationExecution::count())->toBe(6);

    $this->artisan('integrations:prune-executions')->assertSuccessful();

    expect(IntegrationExecution::where('integration_id', $integration->id)->count())->toBe(3);
});

test('pruning preserves executions within the retention window', function () {
    $user = User::factory()->create(['organization_id' => null]);
    $integration = Integration::factory()->forUser($user)->create();

    config(['integrations.execution_retention.count' => 1]);
    config(['integrations.execution_retention.days' => 30]);

    // 2 recent, 2 old.
    IntegrationExecution::factory()->forIntegration($integration)->count(2)
        ->create(['created_at' => now()->subDays(1)]);
    IntegrationExecution::factory()->forIntegration($integration)->count(2)
        ->create(['created_at' => now()->subDays(60)]);

    $this->artisan('integrations:prune-executions')->assertSuccessful();

    // keep 1 by count from the newest + anything < 30 days.
    $remaining = IntegrationExecution::where('integration_id', $integration->id)->get();
    expect($remaining)->toHaveCount(2);
    foreach ($remaining as $row) {
        expect($row->created_at->lt(now()->subDays(30)))->toBeFalse();
    }
});
