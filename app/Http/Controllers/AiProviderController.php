<?php

namespace App\Http\Controllers;

use App\Enums\Visibility;
use App\Models\AiProvider;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'name' => 'required|string|max:50',
            'driver' => 'required|string|max:50',
            'display_name' => 'required|string|max:100',
            'credentials' => 'required|array',
            'credentials.api_key' => 'required|string',
            'models' => 'nullable|array',
            'models.*.id' => 'required|string',
            'models.*.label' => 'required|string',
            'models.*.capabilities' => 'required|array',
            'is_default' => 'boolean',
            'is_default_embeddings' => 'boolean',
            'status' => 'string|in:active,inactive',
        ]);

        $user = $request->user();

        // If setting as default, unset others in the same context
        if ($validated['is_default'] ?? false) {
            AiProvider::forAccountContext($user)->update(['is_default' => false]);
        }
        if ($validated['is_default_embeddings'] ?? false) {
            AiProvider::forAccountContext($user)->update(['is_default_embeddings' => false]);
        }

        AiProvider::create([
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'visibility' => $user->organization_id ? Visibility::Organization : Visibility::Private,
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'display_name' => $validated['display_name'],
            'credentials' => $validated['credentials'],
            'models' => $validated['models'] ?? [],
            'is_default' => $validated['is_default'] ?? false,
            'is_default_embeddings' => $validated['is_default_embeddings'] ?? false,
            'status' => $validated['status'] ?? 'active',
        ]);

        return to_route('system.ai-providers.index');
    }

    public function edit(Request $request, AiProvider $aiProvider): Response
    {
        if (! $aiProvider->isVisibleTo($request->user())) {
            abort(403);
        }

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
        if (! $aiProvider->isOwnedBy($request->user())) {
            abort(403);
        }

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
        if (! $aiProvider->isOwnedBy($request->user())) {
            abort(403);
        }

        $aiProvider->delete();

        return to_route('system.ai-providers.index');
    }

    public function testConnection(Request $request, AiProvider $aiProvider): JsonResponse
    {
        if (! $aiProvider->isVisibleTo($request->user())) {
            abort(403);
        }

        $result = $this->aiProviderService->testConnection($aiProvider);

        return response()->json($result);
    }

    public function setDefault(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        if (! $aiProvider->isVisibleTo($request->user())) {
            abort(403);
        }

        $user = $request->user();

        AiProvider::forAccountContext($user)->update(['is_default' => false]);
        $aiProvider->update(['is_default' => true]);

        return back();
    }

    public function setDefaultEmbeddings(Request $request, AiProvider $aiProvider): RedirectResponse
    {
        if (! $aiProvider->isVisibleTo($request->user())) {
            abort(403);
        }

        $user = $request->user();

        AiProvider::forAccountContext($user)->update(['is_default_embeddings' => false]);
        $aiProvider->update(['is_default_embeddings' => true]);

        return back();
    }
}
