<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GlobalAiController extends Controller
{
    public function __construct(
        private AiProviderService $aiProviderService,
    ) {}

    public function index(): Response
    {
        $llmProvider = $this->aiProviderService->getGlobalDefaultLlmProvider();
        $embeddingsProvider = $this->aiProviderService->getGlobalDefaultEmbeddingsProvider();

        $catalogRows = AiCatalogModel::query()
            ->orderBy('driver')
            ->orderBy('capability')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('admin/GlobalAi', [
            'drivers' => $this->aiProviderService->getAvailableDrivers(),
            'driverOptions' => $this->driverOptions(),
            'existing' => [
                'llm' => $llmProvider ? $this->presentExisting($llmProvider, 'chat') : null,
                'embeddings' => $embeddingsProvider ? $this->presentExisting($embeddingsProvider, 'embeddings') : null,
            ],
            'catalog' => [
                'chat' => $this->presentCatalogRows($catalogRows->where('capability', 'chat')),
                'embeddings' => $this->presentCatalogRows($catalogRows->where('capability', 'embeddings')),
            ],
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        // Case 1: test an already-saved global provider by slot.
        if ($request->filled('slot')) {
            $request->validate([
                'slot' => ['required', Rule::in(['llm', 'embeddings'])],
            ]);

            $provider = $request->input('slot') === 'llm'
                ? $this->aiProviderService->getGlobalDefaultLlmProvider()
                : $this->aiProviderService->getGlobalDefaultEmbeddingsProvider();

            if (! $provider) {
                return response()->json([
                    'success' => false,
                    'message' => __('No global provider configured for this slot.'),
                ]);
            }

            return response()->json($this->aiProviderService->testConnection($provider));
        }

        // Case 2: test a raw payload (driver + credentials + optional model) that
        // the admin is typing into the form before saving.
        $validated = $request->validate([
            'driver' => 'required|string|max:50',
            'credentials' => 'required|array',
            'credentials.api_key' => 'required|string',
            'model_id' => 'nullable|string|max:100',
        ]);

        $result = $this->aiProviderService->testConnectionForPayload(
            $validated['driver'],
            $validated['credentials'],
            $validated['model_id'] ?? null,
        );

        return response()->json($result);
    }

    public function catalogStore(Request $request): RedirectResponse
    {
        $validated = $this->validateCatalogPayload($request);

        AiCatalogModel::create($validated + ['is_enabled' => $validated['is_enabled'] ?? true]);

        return to_route('admin.system.global-ai');
    }

    public function catalogUpdate(Request $request, AiCatalogModel $catalogModel): RedirectResponse
    {
        $validated = $this->validateCatalogPayload($request, $catalogModel);

        $catalogModel->update($validated);

        return to_route('admin.system.global-ai');
    }

    public function catalogDestroy(AiCatalogModel $catalogModel): RedirectResponse
    {
        $catalogModel->delete();

        return to_route('admin.system.global-ai');
    }

    /**
     * Build the list of driver options that are referenced by the catalog form
     * (driver key + human label, no models or credential fields attached).
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function driverOptions(): array
    {
        return collect(AiProviderService::DRIVER_LABELS)
            ->map(fn (string $label, string $driver) => [
                'value' => $driver,
                'label' => $label,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  iterable<AiCatalogModel>  $rows
     * @return array<int, array{id: int, driver: string, driver_label: string, model_id: string, label: string, capability: string, is_enabled: bool, sort_order: int, available_since: string|null}>
     */
    private function presentCatalogRows(iterable $rows): array
    {
        $driverLabels = AiProviderService::DRIVER_LABELS;

        return collect($rows)->map(fn (AiCatalogModel $row) => [
            'id' => $row->id,
            'driver' => $row->driver,
            'driver_label' => $driverLabels[$row->driver] ?? $row->driver,
            'model_id' => $row->model_id,
            'label' => $row->label,
            'capability' => $row->capability,
            'is_enabled' => $row->is_enabled,
            'sort_order' => $row->sort_order,
            'available_since' => $row->created_at?->toIso8601String(),
        ])->values()->all();
    }

    private function validateCatalogPayload(Request $request, ?AiCatalogModel $existing = null): array
    {
        $drivers = array_keys(AiProviderService::DRIVER_LABELS);

        $uniqueRule = Rule::unique('ai_catalog_models')
            ->where(fn ($q) => $q
                ->where('driver', $request->input('driver'))
                ->where('model_id', $request->input('model_id'))
                ->where('capability', $request->input('capability')));

        if ($existing) {
            $uniqueRule->ignore($existing->id);
        }

        return $request->validate([
            'driver' => ['required', 'string', Rule::in($drivers)],
            'model_id' => ['required', 'string', 'max:150', $uniqueRule],
            'label' => ['required', 'string', 'max:150'],
            'capability' => ['required', 'string', Rule::in(['chat', 'embeddings'])],
            'is_enabled' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
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

        // Only one global default at a time for each capability
        AiProvider::query()
            ->where('visibility', Visibility::Global)
            ->update([
                'is_default' => false,
                'is_default_embeddings' => false,
            ]);

        if ($llm['driver'] === $embeddings['driver']) {
            $provider = $this->aiProviderService->upsertGlobalProviderForDriver($llm['driver'], $llm['credentials']);
            $provider->update([
                'models' => $llmModel['id'] === $embeddingsModel['id']
                    ? [$llmModel]
                    : [$llmModel, $embeddingsModel],
                'is_default' => true,
                'is_default_embeddings' => true,
                'status' => 'active',
            ]);
        } else {
            $llmProvider = $this->aiProviderService->upsertGlobalProviderForDriver($llm['driver'], $llm['credentials']);
            $llmProvider->update([
                'models' => [$llmModel],
                'is_default' => true,
                'is_default_embeddings' => false,
                'status' => 'active',
            ]);

            $embeddingsProvider = $this->aiProviderService->upsertGlobalProviderForDriver($embeddings['driver'], $embeddings['credentials']);
            $embeddingsProvider->update([
                'models' => [$embeddingsModel],
                'is_default' => false,
                'is_default_embeddings' => true,
                'status' => 'active',
            ]);
        }

        return to_route('admin.system.global-ai');
    }

    /**
     * Present an existing global provider for the admin UI, masking credentials
     * and exposing only the model that fills the relevant default slot.
     */
    private function presentExisting(AiProvider $provider, string $capability): array
    {
        $model = collect($provider->models ?? [])
            ->first(fn (array $m) => in_array($capability, $m['capabilities'] ?? [], true));

        return [
            'driver' => $provider->driver,
            'display_name' => $provider->display_name,
            'model_id' => $model['id'] ?? null,
            'model_label' => $model['label'] ?? null,
            'masked_credentials' => $this->aiProviderService->maskCredentials($provider->credentials ?? []),
        ];
    }
}
