<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\OrganizationAiBudget;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageReport;
use App\Services\AiProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin v2 Global AI — Defaults + Catalog + Usage tabs. Each tab is its own
 * Inertia route so tab switches are shareable URLs. Settings live in
 * `app_settings` under the `admin_v2.ai.*` namespace; provider credentials
 * and per-row catalog state stay on the existing AiProvider / AiCatalogModel
 * tables the legacy admin already manages.
 */
class AdminAiController extends Controller
{
    public function __construct(
        private AiProviderService $aiProviderService,
        private AiDefaults $aiDefaults,
    ) {}

    public function defaults(): Response
    {
        // Enabled models grouped by the capability each default category needs,
        // so the picker only offers eligible models per category.
        $capabilities = array_values(array_unique(array_values(AiDefaults::CAPABILITY)));
        $modelsByCapability = [];
        foreach ($capabilities as $capability) {
            $modelsByCapability[$capability] = $this->serialiseEnabledModels($capability);
        }

        return Inertia::render('admin/Ai/Defaults', [
            'modules' => AiDefaults::MODULES,
            'capabilityModules' => AiDefaults::CAPABILITY_MODULES,
            'moduleCapability' => AiDefaults::CAPABILITY,
            'defaults' => $this->readDefaults(),
            'modelsByCapability' => $modelsByCapability,
        ]);
    }

    public function providers(): Response
    {
        $state = $this->readGlobalProviderState();
        $counts = $this->enabledModelCountsByDriver();

        $providers = collect(AiProviderService::DRIVER_LABELS)
            ->map(function (string $label, string $driver) use ($state, $counts) {
                $row = $state[$driver] ?? null;
                // A driver whose key only lives in .env (config/ai.php) still
                // works at runtime — reflect it as configured (source: env). A
                // saved global DB row wins (source: db) since it overrides config.
                $envKey = (string) config("ai.providers.{$driver}.key", '');
                $source = $this->aiProviderService->driverConfiguredSource($driver);

                return [
                    'driver' => $driver,
                    'label' => $label,
                    'kind' => $this->aiProviderService->isBroker($driver) ? 'broker' : 'direct',
                    'credentialFields' => AiProviderService::DRIVER_CREDENTIAL_FIELDS[$driver] ?? ['api_key'],
                    'configured' => $source !== null,
                    'source' => $source,
                    'masked' => $row['masked'] ?? ($envKey !== '' ? $this->maskKey($envKey) : null),
                    'lastRotatedAt' => $row['lastRotatedAt'] ?? null,
                    'syncable' => $this->aiProviderService->isSyncable($driver),
                    'modelCount' => (int) ($counts[$driver] ?? 0),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('admin/Ai/Providers', [
            'providers' => $providers,
        ]);
    }

    public function catalog(): Response
    {
        return Inertia::render('admin/Ai/Catalog', [
            'models' => AiCatalogModel::query()
                ->orderBy('driver')
                ->orderBy('capability')
                ->orderBy('label')
                ->get()
                ->map(fn ($m) => $this->serialiseModel($m))
                ->values(),
        ]);
    }

    public function usage(Request $request, AiUsageReport $report): Response
    {
        $days = (int) ($request->integer('days') ?: 30);
        if (! in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        return Inertia::render('admin/Ai/Usage', [
            'days' => $days,
            'report' => $report->platformWide($days),
            'caps' => OrganizationAiBudget::query()
                ->whereNotNull('platform_system_cap')
                ->pluck('platform_system_cap', 'organization_id'),
        ]);
    }

    /**
     * Sysadmin sets the platform's hard ceiling on an org's system spend.
     */
    public function updateBudgetCap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:platform.organizations,id'],
            'platform_system_cap' => ['nullable', 'numeric', 'min:0'],
        ]);

        OrganizationAiBudget::updateOrCreate(
            ['organization_id' => $data['organization_id']],
            ['platform_system_cap' => $data['platform_system_cap']],
        );

        return back()->with('success', 'Platform spend cap updated.');
    }

    public function updateDefaults(Request $request): RedirectResponse
    {
        // Per-category primary/fallback model. The value is an ai_catalog_models
        // id (what the select submits); null/'' clears it. Each category only
        // accepts models of its capability (chat, embeddings, vision, image, …).
        $rules = [];
        foreach (AiDefaults::MODULES as $module) {
            $capability = $this->aiDefaults->capabilityFor($module);
            $modelRule = ['sometimes', 'nullable', Rule::exists('ai_catalog_models', 'id')->where('capability', $capability)];
            $rules["{$module}.primary"] = $modelRule;
            $rules["{$module}.fallback"] = $modelRule;
        }

        $validated = $request->validate($rules);

        foreach (AiDefaults::MODULES as $module) {
            foreach (['primary', 'fallback'] as $slot) {
                if ($request->has("{$module}.{$slot}")) {
                    $this->aiDefaults->setCatalogId(
                        $module,
                        $slot,
                        $this->nullableString(data_get($validated, "{$module}.{$slot}")),
                    );
                }
            }
        }

        return back()->with('success', __('AI defaults updated.'));
    }

    public function toggleModel(Request $request, AiCatalogModel $model): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'label' => ['sometimes', 'string', 'max:255'],
        ]);

        // A model may only be enabled when its provider is connected (has a
        // global key). Disabling is always allowed so an orphaned row can be
        // turned off after its provider key is removed.
        if (($validated['enabled'] ?? false) === true && ! $this->aiProviderService->isDriverConfigured($model->driver)) {
            throw ValidationException::withMessages([
                'enabled' => __('Connect the :provider provider with an API key before enabling its models.', [
                    'provider' => AiProviderService::DRIVER_LABELS[$model->driver] ?? $model->driver,
                ]),
            ]);
        }

        $update = [];
        if ($request->has('enabled')) {
            $update['is_enabled'] = $validated['enabled'];
        }
        if ($request->has('label')) {
            $update['label'] = $validated['label'];
        }

        if ($update !== []) {
            $model->update($update);
        }

        return back()->with('success', __('Catalog model updated.'));
    }

    /**
     * Probe a single catalog model with a real, minimal invocation so the
     * admin can confirm the exact model id is valid and reachable. Read-only.
     */
    public function testCatalogModel(AiCatalogModel $model): JsonResponse
    {
        $result = $this->aiProviderService->testCatalogModel(
            $model->driver,
            $model->model_id,
            $model->capability,
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'detail' => $result['detail'] ?? null,
        ]);
    }

    /**
     * Register or rotate the global API key for a known driver. Handles both
     * the first-time "dar de alta" and subsequent rotations — whatever was
     * stored is overwritten in-place, invalidating the previous key.
     */
    public function setProviderKey(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'string', Rule::in(array_keys(AiProviderService::DRIVER_LABELS))],
            'credentials' => ['required', 'array'],
            'credentials.api_key' => ['required', 'string', 'min:16', 'max:500'],
            'credentials.url' => ['nullable', 'string', 'url', 'max:255'],
        ]);

        $driver = $validated['driver'];

        $credentials = array_filter([
            'api_key' => $validated['credentials']['api_key'],
            'url' => $validated['credentials']['url'] ?? null,
            'rotated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');

        $provider = $this->aiProviderService->upsertGlobalProviderForDriver($driver, $credentials);

        Log::channel('daily')->info('admin_v2.ai.key_set', [
            'provider_id' => $provider->id,
            'driver' => $driver,
            'by_user_id' => $request->user()?->id,
        ]);

        return back()->with('success', __('API key saved.'));
    }

    /**
     * Fetch the live OpenRouter model catalog for the broker picker.
     */
    public function openRouterModels(): JsonResponse
    {
        $credentials = $this->aiProviderService->resolveGlobalCredentials('openrouter');
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if ($apiKey === '') {
            return response()->json(['models' => [], 'enabled' => [], 'error' => __('No OpenRouter API key configured.')]);
        }

        return response()->json([
            'models' => $this->aiProviderService->fetchOpenRouterModels($apiKey, $credentials['url'] ?? null),
            'enabled' => AiCatalogModel::query()
                ->where('driver', 'openrouter')
                ->pluck('model_id')
                ->values()
                ->all(),
        ]);
    }

    /**
     * Persist the admin's OpenRouter model selection into the shared catalog.
     */
    public function saveOpenRouterModels(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'models' => ['present', 'array'],
            'models.*.id' => ['required', 'string', 'max:255'],
            'models.*.label' => ['required', 'string', 'max:255'],
            'models.*.contextWindow' => ['nullable', 'integer', 'min:0'],
            'models.*.inputPricePerMTok' => ['nullable', 'numeric', 'min:0'],
            'models.*.outputPricePerMTok' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->aiProviderService->syncOpenRouterCatalogModels($validated['models']);

        return back()->with('success', __('OpenRouter models updated.'));
    }

    /**
     * Refresh a direct provider's catalog live from its `/models` endpoint.
     */
    public function syncProviderModels(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'string', Rule::in(AiProviderService::SYNCABLE_DRIVERS)],
        ]);

        $driver = $validated['driver'];

        $credentials = $this->aiProviderService->resolveGlobalCredentials($driver);

        if (empty($credentials['api_key'] ?? null)) {
            return back()->with('error', __('Add an API key before syncing models.'));
        }

        $models = $this->aiProviderService->fetchProviderModels($driver, $credentials);

        if ($models === []) {
            return back()->with('error', __('No models returned — check the API key and try again.'));
        }

        $created = $this->aiProviderService->syncDirectCatalogModels($driver, $models);

        Log::channel('daily')->info('admin_v2.ai.models_synced', [
            'driver' => $driver,
            'fetched' => count($models),
            'created' => $created,
            'by_user_id' => $request->user()?->id,
        ]);

        return back()->with('success', __(':count new models added.', ['count' => $created]));
    }

    /**
     * Probe the live connection for a configured driver. Resolves credentials
     * the same way the rest of the tab does — the saved global DB row wins,
     * otherwise the config/.env key — so env-sourced providers (the common
     * case) can be tested too.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'string', Rule::in(array_keys(AiProviderService::DRIVER_LABELS))],
        ]);

        $result = $this->aiProviderService->testConfiguredDriver($validated['driver']);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'detail' => $result['detail'] ?? null,
        ]);
    }

    // ── private helpers ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    /**
     * Per-module primary/fallback as catalog ids (what the admin selects use).
     *
     * @return array<string, array{primary: ?string, fallback: ?string}>
     */
    private function readDefaults(): array
    {
        $out = [];
        foreach (AiDefaults::MODULES as $module) {
            $out[$module] = [
                'primary' => $this->aiDefaults->primaryId($module),
                'fallback' => $this->aiDefaults->fallbackId($module),
            ];
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serialiseEnabledModels(string $capability): array
    {
        return AiCatalogModel::query()
            ->where('capability', $capability)
            ->where('is_enabled', true)
            ->orderBy('driver')
            ->orderBy('label')
            ->get()
            ->map(fn ($m) => $this->serialiseModel($m))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiseModel(AiCatalogModel $model): array
    {
        return [
            'id' => (string) $model->id,
            'driver' => $model->driver,
            'name' => $model->model_id,
            'label' => $model->label,
            // Raw capability (chat, embeddings, vision, image, transcription, …).
            'capability' => $model->capability,
            // Legacy coarse kind kept for the catalog view's chat/embedding split.
            'kind' => $model->capability === 'embeddings' ? 'embedding' : 'chat',
            // Group catalog rows by direct provider vs broker/aggregator.
            'providerKind' => $this->aiProviderService->isBroker($model->driver) ? 'broker' : 'direct',
            'enabled' => (bool) $model->is_enabled,
            // Whether the model's provider is connected (has a global key). The
            // catalog UI disables the enable toggle when this is false.
            'providerConfigured' => $this->aiProviderService->isDriverConfigured($model->driver),
            'contextWindow' => $model->context_window,
            'inputPricePerMTok' => $model->input_price_per_mtok,
            'outputPricePerMTok' => $model->output_price_per_mtok,
            'registeredAt' => $model->created_at?->toIso8601String(),
        ];
    }

    /**
     * Global provider rows keyed by driver, with the masked key and last
     * rotation timestamp. Used by the Providers tab to annotate which of the
     * known drivers already have a global API key configured.
     *
     * @return array<string, array{masked: string, lastRotatedAt: ?string}>
     */
    private function readGlobalProviderState(): array
    {
        return AiProvider::query()
            ->where('visibility', 'global')
            ->get()
            ->mapWithKeys(function (AiProvider $p) {
                $creds = $p->credentials ?? [];

                return [$p->driver => [
                    'masked' => $this->maskKey((string) ($creds['api_key'] ?? '')),
                    'lastRotatedAt' => $creds['rotated_at'] ?? null,
                ]];
            })
            ->all();
    }

    /**
     * Enabled catalog model count per driver, keyed by driver.
     *
     * @return array<string, int>
     */
    private function enabledModelCountsByDriver(): array
    {
        return AiCatalogModel::query()
            ->where('is_enabled', true)
            ->selectRaw('driver, count(*) as aggregate')
            ->groupBy('driver')
            ->pluck('aggregate', 'driver')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function maskKey(string $key): string
    {
        if ($key === '') {
            return '—';
        }
        if (strlen($key) <= 10) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 6).'…'.substr($key, -4);
    }
}
