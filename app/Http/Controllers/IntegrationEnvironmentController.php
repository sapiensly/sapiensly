<?php

namespace App\Http\Controllers;

use App\Http\Requests\Integrations\StoreIntegrationEnvironmentRequest;
use App\Http\Requests\Integrations\UpdateIntegrationEnvironmentRequest;
use App\Models\Integration;
use App\Models\IntegrationEnvironment;
use App\Services\Integrations\IntegrationEnvironmentService;
use Illuminate\Http\RedirectResponse;

class IntegrationEnvironmentController extends Controller
{
    public function __construct(
        private IntegrationEnvironmentService $service,
    ) {}

    public function store(StoreIntegrationEnvironmentRequest $request, Integration $integration): RedirectResponse
    {
        $this->authorize('update', $integration);

        $this->service->create($integration, $request->validated());

        return to_route('system.integrations.show', ['integration' => $integration->id]);
    }

    public function update(UpdateIntegrationEnvironmentRequest $request, IntegrationEnvironment $environment): RedirectResponse
    {
        $this->authorize('update', $environment->integration);

        $this->service->update($environment, $request->validated());

        return to_route('system.integrations.show', ['integration' => $environment->integration_id]);
    }

    public function destroy(IntegrationEnvironment $environment): RedirectResponse
    {
        $this->authorize('update', $environment->integration);

        $this->service->delete($environment);

        return to_route('system.integrations.show', ['integration' => $environment->integration_id]);
    }

    public function activate(IntegrationEnvironment $environment): RedirectResponse
    {
        $this->authorize('update', $environment->integration);

        $this->service->activate($environment);

        return to_route('system.integrations.show', ['integration' => $environment->integration_id]);
    }
}
