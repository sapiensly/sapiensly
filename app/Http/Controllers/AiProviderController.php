<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\AiProviderService;
use App\Services\KnowledgeBaseReindexer;
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
        $user = $request->user();
        $existing = AiProvider::forAccountContext($user)->get();

        return Inertia::render('system/AiProviderForm', [
            'drivers' => $this->aiProviderService->getAvailableDrivers(),
            'mode' => 'create',
            'configuredDrivers' => $existing->pluck('driver')->unique()->values()->all(),
            'hasDefaultChat' => $existing->contains('is_default', true),
            'hasDefaultEmbeddings' => $existing->contains('is_default_embeddings', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => 'required|string|max:50',
            'credentials' => 'required|array',
            'credentials.api_key' => 'required|string',
            'chat_model_id' => 'nullable|string|max:100',
            'embeddings_model_id' => 'nullable|string|max:100',
            'make_default_chat' => 'boolean',
            'make_default_embeddings' => 'boolean',
        ]);

        $driver = $validated['driver'];

        if (empty($validated['chat_model_id']) && empty($validated['embeddings_model_id'])) {
            throw ValidationException::withMessages([
                'chat_model_id' => __('Select at least a chat model or an embeddings model.'),
            ]);
        }

        $models = [];

        if (! empty($validated['chat_model_id'])) {
            $chatModel = $this->aiProviderService->findModelInCatalog($driver, $validated['chat_model_id']);
            if ($chatModel === null || ! in_array('chat', $chatModel['capabilities'], true)) {
                throw ValidationException::withMessages([
                    'chat_model_id' => __('Selected chat model is not a valid chat model.'),
                ]);
            }
            $models[] = $chatModel;
        }

        if (! empty($validated['embeddings_model_id'])) {
            $embeddingsModel = $this->aiProviderService->findModelInCatalog($driver, $validated['embeddings_model_id']);
            if ($embeddingsModel === null || ! in_array('embeddings', $embeddingsModel['capabilities'], true)) {
                throw ValidationException::withMessages([
                    'embeddings_model_id' => __('Selected embeddings model is not a valid embeddings model.'),
                ]);
            }
            // De-duplicate when the same model carries both capabilities.
            if (! collect($models)->contains('id', $embeddingsModel['id'])) {
                $models[] = $embeddingsModel;
            }
        }

        $user = $request->user();
        $visibility = $user->organization_id ? Visibility::Organization : Visibility::Private;

        $provider = $this->upsertProviderForDriver($user, $visibility, $driver, $validated['credentials']);
        $provider->update([
            'models' => $models,
            'status' => 'active',
        ]);

        // Defaults change only when the user opts in — adding a provider never
        // clobbers existing defaults otherwise.
        $makeDefaultChat = ($validated['make_default_chat'] ?? false) && ! empty($validated['chat_model_id']);
        $makeDefaultEmbeddings = ($validated['make_default_embeddings'] ?? false) && ! empty($validated['embeddings_model_id']);

        if ($makeDefaultChat) {
            AiProvider::forAccountContext($user)->where('id', '!=', $provider->id)->update(['is_default' => false]);
        }
        if ($makeDefaultEmbeddings) {
            AiProvider::forAccountContext($user)->where('id', '!=', $provider->id)->update(['is_default_embeddings' => false]);
        }
        $provider->update([
            'is_default' => $makeDefaultChat ? true : $provider->is_default,
            'is_default_embeddings' => $makeDefaultEmbeddings ? true : $provider->is_default_embeddings,
        ]);

        if ($makeDefaultEmbeddings) {
            $this->reindexStaleKnowledgeBases($user);
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

        $becomesDefaultEmbeddings = ($validated['is_default_embeddings'] ?? false) && ! $aiProvider->is_default_embeddings;

        $aiProvider->update($updateData);

        if ($becomesDefaultEmbeddings) {
            $this->reindexStaleKnowledgeBases($user);
        }

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

        $wasDefaultEmbeddings = $aiProvider->is_default_embeddings;

        AiProvider::forAccountContext($user)->update(['is_default_embeddings' => false]);
        $aiProvider->update(['is_default_embeddings' => true]);

        if (! $wasDefaultEmbeddings) {
            $this->reindexStaleKnowledgeBases($user);
        }

        return back();
    }

    /**
     * Re-embed every knowledge base whose chunks no longer match the user's
     * current embedding model. Triggered after the default embeddings provider
     * changes, since that retroactively changes the model each KB resolves to.
     */
    private function reindexStaleKnowledgeBases(User $user): void
    {
        app(KnowledgeBaseReindexer::class)->reprocessStaleForUser($user);
    }
}
