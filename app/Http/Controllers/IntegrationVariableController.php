<?php

namespace App\Http\Controllers;

use App\Http\Requests\Integrations\StoreIntegrationVariableRequest;
use App\Http\Requests\Integrations\UpdateIntegrationVariableRequest;
use App\Models\IntegrationEnvironment;
use App\Models\IntegrationVariable;
use Illuminate\Http\RedirectResponse;

class IntegrationVariableController extends Controller
{
    public function store(StoreIntegrationVariableRequest $request, IntegrationEnvironment $environment): RedirectResponse
    {
        $this->authorize('update', $environment->integration);

        IntegrationVariable::create([
            'integration_environment_id' => $environment->id,
            'key' => $request->input('key'),
            'value' => $request->input('value', ''),
            'is_secret' => (bool) $request->input('is_secret', false),
            'description' => $request->input('description'),
        ]);

        return to_route('system.integrations.show', ['integration' => $environment->integration_id]);
    }

    public function update(UpdateIntegrationVariableRequest $request, IntegrationVariable $variable): RedirectResponse
    {
        $environment = $variable->environment;
        $this->authorize('update', $environment->integration);

        $patch = $request->validated();

        // When updating a secret, an empty submitted value means "keep existing".
        if ($variable->is_secret && isset($patch['value']) && $patch['value'] === '') {
            unset($patch['value']);
        }

        $variable->update($patch);

        return to_route('system.integrations.show', ['integration' => $environment->integration_id]);
    }

    public function destroy(IntegrationVariable $variable): RedirectResponse
    {
        $environment = $variable->environment;
        $this->authorize('update', $environment->integration);

        $integrationId = $environment->integration_id;
        $variable->delete();

        return to_route('system.integrations.show', ['integration' => $integrationId]);
    }
}
