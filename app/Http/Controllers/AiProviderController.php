<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Models\AiProvider;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    public function __construct(
        private AiProviderService $aiProviderService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $providers = $this->aiProviderService->getAllProvidersForContext($user);

        $configuredDrivers = $providers->pluck('driver')->unique()->all();

        return Inertia::render('system/AiProviders', [
            'providers' => $providers->map(fn (AiProvider $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'driver' => $p->driver,
                'display_name' => $p->display_name,
                'models_count' => count($p->models ?? []),
                'chat_models_count' => count($p->getChatModels()),
                'embedding_models_count' => count($p->getEmbeddingModels()),
                'is_default' => $p->is_default,
                'is_default_embeddings' => $p->is_default_embeddings,
                'status' => $p->status,
                'created_at' => $p->created_at->toDateTimeString(),
            ])->values()->all(),
            'configuredDrivers' => $configuredDrivers,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('system/AiProviderForm', [
            'drivers' => $this->aiProviderService->getAvailableDrivers(),
            'mode' => 'create',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'llm.driver' => 'required|string|max:50',
            'llm.model_id' => 'required|string|max:100',
            'llm.credentials' => 'required|array',
            'llm.credentials.api_key' => 'required|string',
            'embeddings.driver' => 'required|string|max:50',
            'embeddings.model_id' => 'required|string|max:100',
            'embeddings.credentials' => 'required|array',
            'embeddings.credentials.api_key' => 'required|string',
        ]);

        $llm = $validated['llm'];
        $embeddings = $validated['embeddings'];

        $llmModel = $this->aiProviderService->findModelInCatalog($llm['driver'], $llm['model_id']);
        $embeddingsModel = $this->aiProviderService->findModelInCatalog($embeddings['driver'], $embeddings['model_id']);

        if ($llmModel === null || ! in_array('chat', $llmModel['capabilities'], true)) {
            throw ValidationException::withMessages([
                'llm.model_id' => __('Selected LLM model is not a valid chat model.'),
            ]);
        }
        if ($embeddingsModel === null || ! in_array('embeddings', $embeddingsModel['capabilities'], true)) {
            throw ValidationException::withMessages([
                'embeddings.model_id' => __('Selected embeddings model is not a valid embeddings model.'),
            ]);
        }

        $user = $request->user();
        $visibility = $user->organization_id ? Visibility::Organization : Visibility::Private;

        // Reset any existing defaults in the current account context
        AiProvider::forAccountContext($user)->update([
            'is_default' => false,
            'is_default_embeddings' => false,
        ]);

        $sameDriver = $llm['driver'] === $embeddings['driver'];

        if ($sameDriver) {
            // Single provider carries both the chat model and the embeddings model,
            // and is marked as both defaults.
            $provider = $this->upsertProviderForDriver($user, $visibility, $llm['driver'], $llm['credentials']);
            $provider->update([
                'models' => $this->mergeModels($llmModel, $embeddingsModel),
                'is_default' => true,
                'is_default_embeddings' => true,
                'status' => 'active',
            ]);
        } else {
            $llmProvider = $this->upsertProviderForDriver($user, $visibility, $llm['driver'], $llm['credentials']);
            $llmProvider->update([
                'models' => [$llmModel],
                'is_default' => true,
                'is_default_embeddings' => false,
                'status' => 'active',
            ]);

            $embeddingsProvider = $this->upsertProviderForDriver($user, $visibility, $embeddings['driver'], $embeddings['credentials']);
            $embeddingsProvider->update([
                'models' => [$embeddingsModel],
                'is_default' => false,
                'is_default_embeddings' => true,
                'status' => 'active',
            ]);
        }

        return to_route('system.ai-providers.index');
    }

    /**
     * Find or create a provider row for the given driver in the user's account context,
     * refreshing its credentials and display name. Other fields (models, defaults) are
     * set by the caller.
     */
    private function upsertProviderForDriver(
        $user,
        Visibility $visibility,
        string $driver,
        array $credentials,
    ): AiProvider {
        $existing = AiProvider::forAccountContext($user)
            ->where('name', $driver)
            ->first();

        if ($existing) {
            $existing->update([
                'driver' => $driver,
                'display_name' => AiProviderService::DRIVER_LABELS[$driver] ?? $driver,
                'credentials' => $credentials,
            ]);

            return $existing;
        }

        return AiProvider::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $visibility,
            'name' => $driver,
            'driver' => $driver,
            'display_name' => AiProviderService::DRIVER_LABELS[$driver] ?? $driver,
            'credentials' => $credentials,
            'models' => [],
            'is_default' => false,
            'is_default_embeddings' => false,
            'status' => 'active',
        ]);
    }

    /**
     * Merge two model catalog entries, de-duplicated by id.
     *
     * @param  array{id: string, label: string, capabilities: array<int, string>}  $a
     * @param  array{id: string, label: string, capabilities: array<int, string>}  $b
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function mergeModels(array $a, array $b): array
    {
        if ($a['id'] === $b['id']) {
            return [$a];
        }

        return [$a, $b];
    }

    public function edit(Request $request, AiProvider $aiProvider): Response
    {
        $this->authorize('view', $aiProvider);

        return Inertia::render('system/AiProviderForm', [
            'drivers' => $this->aiProviderService->getAvailableDrivers(),
            'mode' => 'edit',
            'provider' => [
                'id' => $aiProvider->id,
                'name' => $aiProvider->name,
                'driver' => $aiProvider->driver,
                'display_name' => $aiProvider->display_name,
                'credentials' => $this->aiProviderService->maskCredentials($aiProvider->credentials ?? []),
                'models' => $aiProvider->models ?? [],
                'is_default' => $aiProvider->is_default,
                'is_default_embeddings' => $aiProvider->is_default_embeddings,
                'status' => $aiProvider->status,
            ],
        ]);
    }

    public function update(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $this->authorize('update', $aiProvider);

        $validated = $request->validate([
            'display_name' => 'required|string|max:100',
            'credentials' => 'nullable|array',
            'credentials.api_key' => 'nullable|string',
            'models' => 'nullable|array',
            'models.*.id' => 'required|string',
            'models.*.label' => 'required|string',
            'models.*.capabilities' => 'required|array',
            'is_default' => 'boolean',
            'is_default_embeddings' => 'boolean',
            'status' => 'string|in:active,inactive',
        ]);

        $user = $request->user();

        // Handle default toggles
        if ($validated['is_default'] ?? false) {
            AiProvider::forAccountContext($user)->where('id', '!=', $aiProvider->id)->update(['is_default' => false]);
        }
        if ($validated['is_default_embeddings'] ?? false) {
            AiProvider::forAccountContext($user)->where('id', '!=', $aiProvider->id)->update(['is_default_embeddings' => false]);
        }

        $updateData = [
            'display_name' => $validated['display_name'],
            'models' => $validated['models'] ?? $aiProvider->models,
            'is_default' => $validated['is_default'] ?? false,
            'is_default_embeddings' => $validated['is_default_embeddings'] ?? false,
            'status' => $validated['status'] ?? $aiProvider->status,
        ];

        // Only update credentials if a new api_key is provided (not masked)
        if (! empty($validated['credentials']['api_key']) && ! str_contains($validated['credentials']['api_key'], '...')) {
            $updateData['credentials'] = array_merge(
                $aiProvider->credentials ?? [],
                array_filter($validated['credentials']),
            );
        } elseif (isset($validated['credentials'])) {
            // Merge non-api_key fields (url, api_version, etc.) keeping the existing api_key
            $newCreds = $validated['credentials'];
            unset($newCreds['api_key']);
            if (! empty($newCreds)) {
                $updateData['credentials'] = array_merge(
                    $aiProvider->credentials ?? [],
                    array_filter($newCreds),
                );
            }
        }

        $aiProvider->update($updateData);

        return to_route('system.ai-providers.index');
    }

    public function destroy(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $this->authorize('delete', $aiProvider);

        $aiProvider->delete();

        return to_route('system.ai-providers.index');
    }

    public function testConnection(Request $request, AiProvider $aiProvider): JsonResponse
    {
        $this->authorize('view', $aiProvider);

        $result = $this->aiProviderService->testConnection($aiProvider);

        return response()->json($result);
    }

    public function setDefault(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $this->authorize('update', $aiProvider);

        $user = $request->user();

        AiProvider::forAccountContext($user)->update(['is_default' => false]);
        $aiProvider->update(['is_default' => true]);

        return back();
    }

    public function setDefaultEmbeddings(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        $this->authorize('update', $aiProvider);

        $user = $request->user();

        AiProvider::forAccountContext($user)->update(['is_default_embeddings' => false]);
        $aiProvider->update(['is_default_embeddings' => true]);

        return back();
    }
}
