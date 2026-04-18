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
        'openrouter' => ['api_key'],
        'voyageai' => ['api_key'],
        'xai' => ['api_key'],
    ];

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
     * Get recommended models per agent type.
     */
    public function getRecommendedModels(): array
    {
        return [
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
     * Inject DB-stored AI provider credentials into Laravel's runtime config.
     * This allows the Laravel AI SDK (AiManager) to pick them up.
     */
    public function applyRuntimeConfig(User $user): void
    {
        $providers = $this->getProvidersForContext($user);

        foreach ($providers as $provider) {
            $config = $this->buildProviderConfig($provider);
            config(["ai.providers.{$provider->name}" => $config]);
        }

        // Set defaults
        $default = $providers->firstWhere('is_default', true);
        if ($default) {
            config(['ai.default' => $default->name]);
        }

        $defaultEmbeddings = $providers->firstWhere('is_default_embeddings', true);
        if ($defaultEmbeddings) {
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
}
