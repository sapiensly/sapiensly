<?php

namespace App\Services\Integrations;

use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use Illuminate\Support\Facades\DB;

class IntegrationEnvironmentService
{
    public function create(Integration $integration, array $attributes): IntegrationEnvironment
    {
        $sortOrder = (int) $integration->environments()->max('sort_order') + 1;

        $environment = IntegrationEnvironment::create([
            'integration_id' => $integration->id,
            'name' => $attributes['name'],
            'sort_order' => $attributes['sort_order'] ?? $sortOrder,
        ]);

        // Auto-activate the very first environment.
        if ($integration->active_environment_id === null) {
            $integration->update(['active_environment_id' => $environment->id]);
        }

        return $environment->fresh();
    }

    public function update(IntegrationEnvironment $environment, array $attributes): IntegrationEnvironment
    {
        $environment->update(array_intersect_key($attributes, array_flip(['name', 'sort_order'])));

        return $environment->fresh();
    }

    public function delete(IntegrationEnvironment $environment): void
    {
        DB::transaction(function () use ($environment) {
            $integration = $environment->integration;

            $environment->delete();

            if ($integration->active_environment_id === $environment->id) {
                $integration->update([
                    'active_environment_id' => $integration->environments()->value('id'),
                ]);
            }
        });
    }

    public function activate(IntegrationEnvironment $environment): Integration
    {
        $environment->integration->update([
            'active_environment_id' => $environment->id,
        ]);

        return $environment->integration->fresh();
    }
}
