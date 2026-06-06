<?php

namespace App\Services;

use App\Enums\Visibility;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;

class AiProviderService
{
    /**
     * Predefined model catalogs per driver.
     */
    public const MODEL_CATALOGS = [
        'anthropic' => [
            ['id' => 'claude-sonnet-4-20250514', 'label' => 'Claude Sonnet 4', 'capabilities' => ['chat']],
            ['id' => 'claude-sonnet-4-5-20250929', 'label' => 'Claude Sonnet 4.5', 'capabilities' => ['chat']],
            ['id' => 'claude-opus-4-20250514', 'label' => 'Claude Opus 4', 'capabilities' => ['chat']],
            ['id' => 'claude-haiku-4-5-20251001', 'label' => 'Claude Haiku 4.5', 'capabilities' => ['chat']],
        ],
        'openai' => [
            ['id' => 'gpt-4o', 'label' => 'GPT-4o', 'capabilities' => ['chat']],
            ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini', 'capabilities' => ['chat']],
            ['id' => 'gpt-4-turbo', 'label' => 'GPT-4 Turbo', 'capabilities' => ['chat']],
            ['id' => 'text-embedding-3-small', 'label' => 'Embedding 3 Small', 'capabilities' => ['embeddings']],
            ['id' => 'text-embedding-3-large', 'label' => 'Embedding 3 Large', 'capabilities' => ['embeddings']],
        ],
        'azure' => [
            ['id' => 'gpt-4o', 'label' => 'GPT-4o (Azure)', 'capabilities' => ['chat']],
            ['id' => 'gpt-4o-mini', 'label' => 'GPT-4o Mini (Azure)', 'capabilities' => ['chat']],
            ['id' => 'text-embedding-3-small', 'label' => 'Embedding 3 Small (Azure)', 'capabilities' => ['embeddings']],
        ],
        'gemini' => [
            ['id' => 'gemini-2.0-flash', 'label' => 'Gemini 2.0 Flash', 'capabilities' => ['chat']],
            ['id' => 'gemini-2.5-pro-preview-05-06', 'label' => 'Gemini 2.5 Pro', 'capabilities' => ['chat']],
        ],
        'mistral' => [
            ['id' => 'mistral-large-latest', 'label' => 'Mistral Large', 'capabilities' => ['chat']],
            ['id' => 'mistral-small-latest', 'label' => 'Mistral Small', 'capabilities' => ['chat']],
            ['id' => 'mistral-embed', 'label' => 'Mistral Embed', 'capabilities' => ['embeddings']],
        ],
        'deepseek' => [
            ['id' => 'deepseek-chat', 'label' => 'DeepSeek Chat', 'capabilities' => ['chat']],
            ['id' => 'deepseek-reasoner', 'label' => 'DeepSeek Reasoner', 'capabilities' => ['chat']],
        ],
        'groq' => [
            ['id' => 'llama-3.3-70b-versatile', 'label' => 'Llama 3.3 70B', 'capabilities' => ['chat']],
            ['id' => 'llama-3.1-8b-instant', 'label' => 'Llama 3.1 8B', 'capabilities' => ['chat']],
        ],
        'xai' => [
            ['id' => 'grok-3', 'label' => 'Grok 3', 'capabilities' => ['chat']],
            ['id' => 'grok-3-mini', 'label' => 'Grok 3 Mini', 'capabilities' => ['chat']],
        ],
        'cohere' => [
            ['id' => 'command-r-plus', 'label' => 'Command R+', 'capabilities' => ['chat']],
            ['id' => 'command-r', 'label' => 'Command R', 'capabilities' => ['chat']],
            ['id' => 'embed-english-v3.0', 'label' => 'Embed English v3', 'capabilities' => ['embeddings']],
        ],
        'voyageai' => [
            ['id' => 'voyage-3', 'label' => 'Voyage 3', 'capabilities' => ['embeddings']],
            ['id' => 'voyage-3-lite', 'label' => 'Voyage 3 Lite', 'capabilities' => ['embeddings']],
        ],
        'jina' => [
            ['id' => 'jina-embeddings-v3', 'label' => 'Jina Embeddings v3', 'capabilities' => ['embeddings']],
        ],
        'ollama' => [],
        'openrouter' => [],
        'eleven' => [],
    ];

    /**
     * Driver display labels.
     */
    public const DRIVER_LABELS = [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'azure' => 'Azure OpenAI',
        'cohere' => 'Cohere',
        'deepseek' => 'DeepSeek',
        'eleven' => 'ElevenLabs',
        'gemini' => 'Google Gemini',
        'groq' => 'Groq',
        'jina' => 'Jina',
        'mistral' => 'Mistral',
        'ollama' => 'Ollama',
        'openrouter' => 'OpenRouter',
        'voyageai' => 'Voyage AI',
        'xai' => 'xAI',
    ];

    /**
     * Credential fields per driver.
     */
    public const DRIVER_CREDENTIAL_FIELDS = [
        'anthropic' => ['api_key'],
        'openai' => ['api_key'],
        'azure' => ['api_key', 'url', 'api_version', 'deployment', 'embedding_deployment'],
        'cohere' => ['api_key'],
        'deepseek' => ['api_key'],
        'eleven' => ['api_key'],
        'gemini' => ['api_key'],
        'groq' => ['api_key'],
        'jina' => ['api_key'],
        'mistral' => ['api_key'],
        'ollama' => ['api_key', 'url'],
        'openrouter' => ['api_key', 'url'],
        'voyageai' => ['api_key'],
        'xai' => ['api_key'],
    ];

    /**
     * Drivers that act as brokers/aggregators (route to many upstream models)
     * rather than a single first-party provider. They expose a large, live
     * model catalog fetched on demand instead of a hardcoded list.
     */
    public const BROKER_DRIVERS = ['openrouter'];

    /**
     * Default base URL for the OpenRouter broker (OpenAI-compatible).
     */
    public const OPENROUTER_BASE_URL = 'https://openrouter.ai/api/v1';

    /**
     * Direct drivers that expose a usable `/models` listing endpoint, so their
     * catalog can be refreshed live. The rest (azure deployments, local ollama,
     * embeddings-only voyageai/jina, eleven) stay curated/manual.
     */
    public const SYNCABLE_DRIVERS = ['anthropic', 'openai', 'gemini', 'mistral', 'groq', 'xai', 'deepseek', 'cohere'];

    /**
     * Whether the given driver is a broker/aggregator rather than a direct provider.
     */
    public function isBroker(string $driver): bool
    {
        return in_array($driver, self::BROKER_DRIVERS, true);
    }

    /**
     * Whether the given driver's catalog can be refreshed live from its API.
     */
    public function isSyncable(string $driver): bool
    {
        return in_array($driver, self::SYNCABLE_DRIVERS, true);
    }

    /**
     * Whether a driver has a usable platform-wide (Global) API key — either a
     * saved global provider row or a key in config/.env. This is the single
     * source of truth for "is this provider connected?", shared by the
     * Providers tab and the Catalog enable rule: a model can only be enabled
     * when its driver is connected, otherwise it would fail at inference time.
     */
    public function isDriverConfigured(string $driver): bool
    {
        return $this->driverConfiguredSource($driver) !== null;
    }

    /**
     * Where a driver's global key comes from: `db` (saved global provider row,
     * which wins), `env` (config/.env only), or null when neither exists.
     */
    public function driverConfiguredSource(string $driver): ?string
    {
        $hasDbRow = AiProvider::query()
            ->where('visibility', 'global')
            ->where('driver', $driver)
            ->exists();

        if ($hasDbRow) {
            return 'db';
        }

        return (string) config("ai.providers.{$driver}.key", '') !== '' ? 'env' : null;
    }

    /**
     * Get all AI providers for the user's current account context.
     */
    public function getProvidersForContext(User $user): Collection
    {
        return AiProvider::forAccountContext($user)
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Get all AI providers (including inactive) for the user's current account context.
     */
    public function getAllProvidersForContext(User $user): Collection
    {
        return AiProvider::forAccountContext($user)
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Get the default LLM provider.
     */
    public function getDefaultProvider(User $user): ?AiProvider
    {
        return AiProvider::forAccountContext($user)
            ->where('is_default', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Get the default embeddings provider.
     */
    public function getDefaultEmbeddingsProvider(User $user): ?AiProvider
    {
        return AiProvider::forAccountContext($user)
            ->where('is_default_embeddings', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Get available chat models for the user's account context.
     * Returns format compatible with frontend: [{value, label, provider}]
     */
    public function getAvailableModels(User $user): array
    {
        $providers = $this->getProvidersForContext($user);

        return $providers->flatMap(function (AiProvider $provider) {
            return collect($provider->getChatModels())->map(fn (array $model) => [
                'value' => $model['id'],
                'label' => $model['label'],
                'provider' => $provider->name,
            ]);
        })->values()->all();
    }

    /**
     * Reachable chat models — every chat model the driver supports in the
     * global catalog for each provider the user has an API key configured
     * for, not just the ones the tenant explicitly toggled into the
     * provider's stored `models` array. This is what users expect when
     * picking a model: "I have an Anthropic key, so I should see all
     * Anthropic chat models." Returns [{value, label, provider}].
     *
     * @return array<int, array{value: string, label: string, provider: string}>
     */
    public function getReachableChatModels(User $user): array
    {
        $providers = $this->getProvidersForContext($user);
        $catalogsByDriver = [];

        foreach ($providers as $provider) {
            $driver = $provider->driver;
            if (! isset($catalogsByDriver[$driver])) {
                $catalogsByDriver[$driver] = $this->getModelCatalog($driver);
            }

            foreach ($catalogsByDriver[$driver] as $model) {
                if (! in_array('chat', $model['capabilities'] ?? [], true)) {
                    continue;
                }
                // Keep the first occurrence per model id so we don't emit
                // duplicates when two providers share a driver.
                $seen[$model['id']] ??= [
                    'value' => $model['id'],
                    'label' => $model['label'],
                    'provider' => $provider->display_name ?? $provider->name,
                ];
            }
        }

        return array_values($seen ?? []);
    }

    /**
     * Every chat model enabled in the shared catalog, independent of whether
     * the tenant has its own key — the platform-wide (Global) keys configured
     * by the sysadmin make them usable, and a tenant's own key transparently
     * overrides the global one for its driver at inference time.
     *
     * When a $user is given, each model is tagged with `source`: `byok` when
     * the tenant has its own active key for that driver, otherwise `system`.
     * Deduped by model id.
     *
     * @return array<int, array{value: string, label: string, provider: string, source?: string}>
     */
    public function getEnabledChatModels(?User $user = null): array
    {
        $byokDrivers = [];
        if ($user !== null) {
            $byokDrivers = $this->getProvidersForContext($user)
                ->pluck('driver')
                ->flip()
                ->all();
        }

        $seen = [];

        $rows = AiCatalogModel::query()
            ->enabled()
            ->chat()
            ->orderBy('driver')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        foreach ($rows as $model) {
            if (isset($seen[$model->model_id])) {
                continue;
            }

            $entry = [
                'value' => $model->model_id,
                'label' => $model->label,
                'provider' => self::DRIVER_LABELS[$model->driver] ?? $model->driver,
            ];

            if ($user !== null) {
                $entry['source'] = isset($byokDrivers[$model->driver]) ? 'byok' : 'system';
            }

            $seen[$model->model_id] = $entry;
        }

        return array_values($seen);
    }

    /**
     * Map a model id to its Lab provider using the shared catalog (the
     * model's `driver` column), so any enabled catalog model resolves whether
     * its key is the platform-wide global one or the tenant's own. Returns
     * null when the model is not in the enabled catalog or its driver is not
     * a known Lab provider.
     */
    public function resolveProviderForCatalogModel(string $modelId, ?User $user = null): ?Lab
    {
        $driver = AiCatalogModel::query()
            ->enabled()
            ->where('model_id', $modelId)
            ->orderBy('driver')
            ->value('driver');

        if ($driver === null) {
            return null;
        }

        try {
            return Lab::from($driver);
        } catch (\ValueError) {
            return null;
        }
    }

    /**
     * Get recommended models per agent type.
     */
    public function getRecommendedModels(): array
    {
        return [
            'general' => ['claude-sonnet-4-20250514', 'gpt-4o'],
            'triage' => ['claude-haiku-4-5-20251001', 'gpt-4o-mini'],
            'knowledge' => ['claude-sonnet-4-20250514', 'gpt-4o'],
            'action' => ['claude-sonnet-4-20250514', 'gpt-4o'],
        ];
    }

    /**
     * Resolve which Lab provider a model belongs to for the given user context.
     */
    public function resolveProvider(string $modelId, User $user): Lab
    {
        $providers = $this->getProvidersForContext($user);

        foreach ($providers as $provider) {
            if ($provider->hasModel($modelId)) {
                return Lab::from($provider->driver);
            }
        }

        // Fallback: try to match from default provider
        $default = $this->getDefaultProvider($user);
        if ($default) {
            return Lab::from($default->driver);
        }

        return Lab::Anthropic;
    }

    /**
     * Inject DB-stored AI provider credentials into Laravel's runtime config so
     * the Laravel AI SDK (AiManager) can pick them up.
     *
     * Layered tenant→global resolution: the platform-wide (Global) keys the
     * sysadmin configured form the base, then the tenant's own keys override
     * them for matching drivers. So a model whose driver only has a global key
     * still works, and a tenant that brings its own key transparently uses it.
     */
    public function applyRuntimeConfig(User $user): void
    {
        // Base layer — global system keys.
        $global = AiProvider::query()
            ->where('visibility', Visibility::Global)
            ->where('status', 'active')
            ->get();

        foreach ($global as $provider) {
            config(["ai.providers.{$provider->name}" => $this->buildProviderConfig($provider)]);
        }

        if ($globalDefault = $global->firstWhere('is_default', true)) {
            config(['ai.default' => $globalDefault->name]);
        }
        if ($globalEmbeddings = $global->firstWhere('is_default_embeddings', true)) {
            config(['ai.default_for_embeddings' => $globalEmbeddings->name]);
        }

        // Override layer — the tenant's own keys win for their drivers.
        $providers = $this->getProvidersForContext($user);

        foreach ($providers as $provider) {
            config(["ai.providers.{$provider->name}" => $this->buildProviderConfig($provider)]);
        }

        if ($default = $providers->firstWhere('is_default', true)) {
            config(['ai.default' => $default->name]);
        }
        if ($defaultEmbeddings = $providers->firstWhere('is_default_embeddings', true)) {
            config(['ai.default_for_embeddings' => $defaultEmbeddings->name]);
        }
    }

    /**
     * Build the config array for a provider (matching Laravel AI SDK expected format).
     */
    private function buildProviderConfig(AiProvider $provider): array
    {
        $credentials = $provider->credentials ?? [];

        $config = [
            'driver' => $provider->driver,
            'key' => $credentials['api_key'] ?? '',
        ];

        // Add driver-specific fields
        if (isset($credentials['url'])) {
            $config['url'] = $credentials['url'];
        }
        if (isset($credentials['api_version'])) {
            $config['api_version'] = $credentials['api_version'];
        }
        if (isset($credentials['deployment'])) {
            $config['deployment'] = $credentials['deployment'];
        }
        if (isset($credentials['embedding_deployment'])) {
            $config['embedding_deployment'] = $credentials['embedding_deployment'];
        }

        return $config;
    }

    /**
     * Get the model catalog for a given driver, reading enabled rows from the
     * database. Returns the same shape as the legacy MODEL_CATALOGS constant
     * so call-sites can treat both transparently.
     *
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    public function getModelCatalog(string $driver): array
    {
        $rows = AiCatalogModel::query()
            ->enabled()
            ->where('driver', $driver)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->groupCatalogRows($rows);
    }

    /**
     * Build the full catalog across all drivers as [driver => models[]].
     *
     * @return array<string, array<int, array{id: string, label: string, capabilities: array<int, string>}>>
     */
    public function getFullCatalog(): array
    {
        $rows = AiCatalogModel::query()
            ->enabled()
            ->orderBy('driver')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $catalog = [];
        foreach ($rows->groupBy('driver') as $driver => $driverRows) {
            $catalog[$driver] = $this->groupCatalogRows($driverRows);
        }

        return $catalog;
    }

    /**
     * Collapse multiple catalog rows (one per capability) into the legacy
     * `[{id, label, capabilities: [...]}]` shape.
     *
     * @param  iterable<AiCatalogModel>  $rows
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function groupCatalogRows(iterable $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $key = $row->model_id;
            if (! isset($byId[$key])) {
                $byId[$key] = [
                    'id' => $row->model_id,
                    'label' => $row->label,
                    'capabilities' => [],
                ];
            }
            $byId[$key]['capabilities'][] = $row->capability;
        }

        return array_values($byId);
    }

    /**
     * Find a model in a driver's catalog by id.
     *
     * @return array{id: string, label: string, capabilities: array<int, string>}|null
     */
    public function findModelInCatalog(string $driver, string $modelId): ?array
    {
        foreach ($this->getModelCatalog($driver) as $model) {
            if ($model['id'] === $modelId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Find or create a platform-wide (Global) provider for the given driver, refreshing
     * its credentials. Other fields (models, defaults) are set by the caller.
     */
    public function upsertGlobalProviderForDriver(string $driver, array $credentials): AiProvider
    {
        $existing = AiProvider::query()
            ->where('visibility', Visibility::Global)
            ->where('name', $driver)
            ->first();

        if ($existing) {
            $existing->update([
                'driver' => $driver,
                'display_name' => self::DRIVER_LABELS[$driver] ?? $driver,
                'credentials' => $credentials,
            ]);

            return $existing;
        }

        return AiProvider::create([
            'user_id' => null,
            'organization_id' => null,
            'visibility' => Visibility::Global,
            'name' => $driver,
            'driver' => $driver,
            'display_name' => self::DRIVER_LABELS[$driver] ?? $driver,
            'credentials' => $credentials,
            'models' => [],
            'is_default' => false,
            'is_default_embeddings' => false,
            'status' => 'active',
        ]);
    }

    /**
     * Get the global default LLM provider, if any.
     */
    public function getGlobalDefaultLlmProvider(): ?AiProvider
    {
        return AiProvider::query()
            ->where('visibility', Visibility::Global)
            ->where('is_default', true)
            ->first();
    }

    /**
     * Get the global default embeddings provider, if any.
     */
    public function getGlobalDefaultEmbeddingsProvider(): ?AiProvider
    {
        return AiProvider::query()
            ->where('visibility', Visibility::Global)
            ->where('is_default_embeddings', true)
            ->first();
    }

    /**
     * Get all available driver options for the create form.
     */
    public function getAvailableDrivers(): array
    {
        $catalog = $this->getFullCatalog();

        return collect(self::DRIVER_LABELS)->map(fn (string $label, string $driver) => [
            'value' => $driver,
            'label' => $label,
            'credential_fields' => self::DRIVER_CREDENTIAL_FIELDS[$driver] ?? ['api_key'],
            'models' => $catalog[$driver] ?? [],
        ])->values()->all();
    }

    /**
     * Test a connection using raw driver + credentials without persisting a provider row.
     * Convenience wrapper around testConnection() for admin setup flows.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnectionForPayload(string $driver, array $credentials, ?string $modelId = null): array
    {
        $model = $modelId ? $this->findModelInCatalog($driver, $modelId) : null;

        $unsaved = new AiProvider([
            'driver' => $driver,
            'credentials' => $credentials,
            'models' => $model ? [$model] : [],
        ]);

        return $this->testConnection($unsaved);
    }

    /**
     * Test the connection to an AI provider by making a lightweight API call.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(AiProvider $provider): array
    {
        $credentials = $provider->credentials ?? [];
        $apiKey = $credentials['api_key'] ?? '';

        if (empty($apiKey)) {
            return ['success' => false, 'message' => __('No API key configured.')];
        }

        try {
            $response = match ($provider->driver) {
                'anthropic' => Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])->timeout(10)->post('https://api.anthropic.com/v1/messages', [
                    'model' => collect($provider->getChatModels())->first()['id'] ?? 'claude-sonnet-4-20250514',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ]),
                'openai' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://api.openai.com/v1/models'),
                'azure' => Http::withHeaders(['api-key' => $apiKey])
                    ->timeout(10)
                    ->get(rtrim($credentials['url'] ?? '', '/').'/openai/models?api-version='.($credentials['api_version'] ?? '2024-02-01')),
                'gemini' => Http::timeout(10)
                    ->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}"),
                'mistral' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://api.mistral.ai/v1/models'),
                'deepseek' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://api.deepseek.com/models'),
                'groq' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://api.groq.com/openai/v1/models'),
                'xai' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://api.x.ai/v1/models'),
                'cohere' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get('https://api.cohere.com/v2/models'),
                'voyageai' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->post('https://api.voyageai.com/v1/embeddings', [
                        'model' => collect($provider->getEmbeddingModels())->first()['id'] ?? 'voyage-3-lite',
                        'input' => ['ping'],
                    ]),
                'jina' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->post('https://api.jina.ai/v1/embeddings', [
                        'model' => collect($provider->getEmbeddingModels())->first()['id'] ?? 'jina-embeddings-v3',
                        'input' => ['ping'],
                    ]),
                'ollama' => Http::timeout(10)
                    ->get(rtrim($credentials['url'] ?? 'http://localhost:11434', '/').'/api/tags'),
                'openrouter' => Http::withToken($apiKey)
                    ->timeout(10)
                    ->get(rtrim($credentials['url'] ?? self::OPENROUTER_BASE_URL, '/').'/models'),
                default => null,
            };

            if ($response === null) {
                return ['success' => false, 'message' => __('Unsupported driver for connection testing.')];
            }

            if ($response->successful()) {
                return ['success' => true, 'message' => __('Connection successful.')];
            }

            $body = $response->json();
            $detail = $body['error']['message']
                ?? $body['error']['description']
                ?? $body['message']
                ?? $body['detail']
                ?? $response->body();

            return [
                'success' => false,
                'message' => __('Connection failed: :status', ['status' => $response->status()]),
                'detail' => $detail,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => __('Connection failed: :error', ['error' => $e->getMessage()]),
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * Mask sensitive credential fields for display.
     */
    public function maskCredentials(array $credentials): array
    {
        $masked = [];
        foreach ($credentials as $key => $value) {
            if ($key === 'api_key' && is_string($value) && strlen($value) > 8) {
                $masked[$key] = substr($value, 0, 4).'...'.substr($value, -4);
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * Fetch the live model catalog from OpenRouter's OpenAI-compatible
     * `/models` endpoint, including context window, pricing and capability
     * metadata for the picker.
     *
     * @return array<int, array{id: string, label: string, contextWindow: ?int, inputPricePerMTok: ?float, outputPricePerMTok: ?float, vision: bool, tools: bool, description: string}>
     */
    public function fetchOpenRouterModels(string $apiKey, ?string $url = null): array
    {
        $base = rtrim($url ?: self::OPENROUTER_BASE_URL, '/');

        $response = Http::withToken($apiKey)->timeout(15)->get($base.'/models');

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->filter(fn ($model) => ! empty($model['id']))
            ->map(function ($model) {
                $architecture = $model['architecture'] ?? [];
                $inputModalities = $architecture['input_modalities'] ?? [];
                $supportedParameters = $model['supported_parameters'] ?? [];

                return [
                    'id' => (string) $model['id'],
                    'label' => (string) ($model['name'] ?? $model['id']),
                    'contextWindow' => isset($model['context_length']) ? (int) $model['context_length'] : null,
                    'inputPricePerMTok' => $this->perMillionPrice($model['pricing']['prompt'] ?? null),
                    'outputPricePerMTok' => $this->perMillionPrice($model['pricing']['completion'] ?? null),
                    'vision' => in_array('image', $inputModalities, true)
                        || str_contains((string) ($architecture['modality'] ?? ''), 'image'),
                    'tools' => in_array('tools', $supportedParameters, true),
                    'description' => (string) ($model['description'] ?? ''),
                    'created' => isset($model['created']) ? (int) $model['created'] : null,
                    // Full upstream payload so the UI can show every available
                    // detail for an informed enable/disable decision.
                    'raw' => $model,
                ];
            })
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Convert OpenRouter's per-token USD price string into a per-million-token
     * price. Returns null when the price is absent (0 stays 0 for free models).
     */
    private function perMillionPrice(mixed $perToken): ?float
    {
        if ($perToken === null || $perToken === '') {
            return null;
        }

        return (float) $perToken * 1_000_000;
    }

    /**
     * Persist the admin's chosen OpenRouter models into the shared
     * `ai_catalog_models` table so they surface in the catalog and model
     * pickers. Rows for openrouter models no longer selected are removed.
     *
     * The label is only set when a row is first created — a manual rename in
     * the catalog survives subsequent re-syncs. Context window and pricing are
     * always refreshed from the latest payload.
     *
     * @param  array<int, array{id: string, label: string}>  $models
     */
    public function syncOpenRouterCatalogModels(array $models): void
    {
        $keepIds = [];

        foreach (array_values($models) as $index => $model) {
            $modelId = (string) ($model['id'] ?? '');
            if ($modelId === '') {
                continue;
            }
            $keepIds[] = $modelId;

            $row = AiCatalogModel::firstOrNew([
                'driver' => 'openrouter',
                'model_id' => $modelId,
                'capability' => 'chat',
            ]);

            // Preserve a manually-edited label on existing rows.
            if (! $row->exists) {
                $row->label = (string) ($model['label'] ?? $modelId);
                $row->sort_order = $index;
            }

            $row->context_window = $model['contextWindow'] ?? null;
            $row->input_price_per_mtok = $model['inputPricePerMTok'] ?? null;
            $row->output_price_per_mtok = $model['outputPricePerMTok'] ?? null;
            $row->is_enabled = true;
            $row->save();
        }

        AiCatalogModel::query()
            ->where('driver', 'openrouter')
            ->whereNotIn('model_id', $keepIds)
            ->delete();
    }

    /**
     * Fetch the live model list for a direct provider from its `/models`
     * endpoint, classified into chat/embeddings capabilities. Returns an empty
     * array for providers without a usable listing endpoint or on failure.
     *
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    public function fetchProviderModels(string $driver, array $credentials): array
    {
        $apiKey = (string) ($credentials['api_key'] ?? '');

        if ($apiKey === '' || ! $this->isSyncable($driver)) {
            return [];
        }

        try {
            $response = match ($driver) {
                'anthropic' => Http::withHeaders([
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])->timeout(15)->get('https://api.anthropic.com/v1/models', ['limit' => 1000]),
                'openai' => Http::withToken($apiKey)->timeout(15)->get('https://api.openai.com/v1/models'),
                'gemini' => Http::timeout(15)->get("https://generativelanguage.googleapis.com/v1beta/models?key={$apiKey}&pageSize=1000"),
                'mistral' => Http::withToken($apiKey)->timeout(15)->get('https://api.mistral.ai/v1/models'),
                'groq' => Http::withToken($apiKey)->timeout(15)->get('https://api.groq.com/openai/v1/models'),
                'xai' => Http::withToken($apiKey)->timeout(15)->get('https://api.x.ai/v1/models'),
                'deepseek' => Http::withToken($apiKey)->timeout(15)->get('https://api.deepseek.com/models'),
                'cohere' => Http::withToken($apiKey)->timeout(15)->get('https://api.cohere.com/v2/models', ['page_size' => 1000]),
                default => null,
            };
        } catch (\Exception) {
            return [];
        }

        if ($response === null || ! $response->successful()) {
            return [];
        }

        return match ($driver) {
            'anthropic' => $this->parseAnthropicModels($response->json()),
            'gemini' => $this->parseGeminiModels($response->json()),
            'mistral' => $this->parseMistralModels($response->json()),
            'cohere' => $this->parseCohereModels($response->json()),
            // OpenAI-compatible `{ data: [{ id }] }` shape.
            default => $this->parseOpenAiStyleModels($response->json()),
        };
    }

    /**
     * Merge a freshly fetched direct-provider model list into the shared
     * catalog: new models are added enabled, labels are refreshed, and existing
     * rows keep their admin enable/disable toggle. Nothing is deleted, so
     * defaults that reference a model id are never orphaned.
     *
     * @param  array<int, array{id: string, label: string, capabilities: array<int, string>}>  $models
     * @return int Number of catalog rows created.
     */
    public function syncDirectCatalogModels(string $driver, array $models): int
    {
        $created = 0;

        foreach (array_values($models) as $index => $model) {
            $modelId = (string) ($model['id'] ?? '');
            if ($modelId === '') {
                continue;
            }

            foreach ($model['capabilities'] ?? ['chat'] as $capability) {
                $row = AiCatalogModel::firstOrNew([
                    'driver' => $driver,
                    'model_id' => $modelId,
                    'capability' => $capability,
                ]);

                $row->label = (string) ($model['label'] ?? $modelId);

                if (! $row->exists) {
                    $row->is_enabled = true;
                    $row->sort_order = $index;
                    $created++;
                }

                $row->save();
            }
        }

        return $created;
    }

    /**
     * Classify a bare model id (OpenAI-style listings carry no metadata) into a
     * capability set, skipping non-text models (audio, image, moderation, …).
     *
     * @return array<int, string>|null
     */
    private function classifyModelId(string $id): ?array
    {
        $lower = strtolower($id);

        foreach (['whisper', 'tts', 'dall', 'image', 'audio', 'moderation', 'rerank', 'stable', 'sora', 'guard', 'clip'] as $skip) {
            if (str_contains($lower, $skip)) {
                return null;
            }
        }

        return str_contains($lower, 'embed') ? ['embeddings'] : ['chat'];
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function parseOpenAiStyleModels(?array $json): array
    {
        return collect($json['data'] ?? [])
            ->map(function ($model) {
                $id = (string) ($model['id'] ?? '');
                $capabilities = $id === '' ? null : $this->classifyModelId($id);

                return $capabilities === null ? null : [
                    'id' => $id,
                    'label' => $id,
                    'capabilities' => $capabilities,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function parseAnthropicModels(?array $json): array
    {
        return collect($json['data'] ?? [])
            ->filter(fn ($model) => ! empty($model['id']))
            ->map(fn ($model) => [
                'id' => (string) $model['id'],
                'label' => (string) ($model['display_name'] ?? $model['id']),
                'capabilities' => ['chat'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function parseGeminiModels(?array $json): array
    {
        return collect($json['models'] ?? [])
            ->map(function ($model) {
                $name = (string) ($model['name'] ?? '');
                $id = preg_replace('#^models/#', '', $name) ?? $name;
                if ($id === '') {
                    return null;
                }

                $methods = $model['supportedGenerationMethods'] ?? [];
                $capabilities = [];
                if (in_array('generateContent', $methods, true)) {
                    $capabilities[] = 'chat';
                }
                if (in_array('embedContent', $methods, true) || in_array('embedText', $methods, true)) {
                    $capabilities[] = 'embeddings';
                }
                if ($capabilities === []) {
                    return null;
                }

                return [
                    'id' => $id,
                    'label' => (string) ($model['displayName'] ?? $id),
                    'capabilities' => $capabilities,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function parseMistralModels(?array $json): array
    {
        return collect($json['data'] ?? [])
            ->map(function ($model) {
                $id = (string) ($model['id'] ?? '');
                if ($id === '') {
                    return null;
                }

                $caps = $model['capabilities'] ?? [];
                $capabilities = [];
                if (! empty($caps['completion_chat']) || ! empty($caps['completion_fim'])) {
                    $capabilities[] = 'chat';
                }
                if (! empty($caps['embeddings']) || str_contains(strtolower($id), 'embed')) {
                    $capabilities[] = 'embeddings';
                }
                // Fall back to id heuristics when the payload omits capabilities.
                if ($capabilities === []) {
                    $capabilities = $this->classifyModelId($id) ?? [];
                }
                if ($capabilities === []) {
                    return null;
                }

                return [
                    'id' => $id,
                    'label' => (string) ($model['name'] ?? $id),
                    'capabilities' => array_values(array_unique($capabilities)),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int, array{id: string, label: string, capabilities: array<int, string>}>
     */
    private function parseCohereModels(?array $json): array
    {
        return collect($json['models'] ?? [])
            ->map(function ($model) {
                $name = (string) ($model['name'] ?? '');
                if ($name === '') {
                    return null;
                }

                $endpoints = $model['endpoints'] ?? [];
                $capabilities = [];
                if (in_array('chat', $endpoints, true)) {
                    $capabilities[] = 'chat';
                }
                if (in_array('embed', $endpoints, true)) {
                    $capabilities[] = 'embeddings';
                }
                if ($capabilities === []) {
                    return null;
                }

                return [
                    'id' => $name,
                    'label' => $name,
                    'capabilities' => $capabilities,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
