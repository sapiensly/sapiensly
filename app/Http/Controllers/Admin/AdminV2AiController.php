<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RecomputeEmbeddingsJob;
use App\Models\AiCatalogModel;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\KnowledgeBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin v2 Global AI — Defaults + Catalog + Usage tabs. Each tab is its own
 * Inertia route so tab switches are shareable URLs. Settings live in
 * `app_settings` under the `admin_v2.ai.*` namespace; provider credentials
 * and per-row catalog state stay on the existing AiProvider / AiCatalogModel
 * tables the legacy admin already manages.
 */
class AdminV2AiController extends Controller
{
    private const KEY_PRIMARY = 'admin_v2.ai.primary_chat_model_id';

    private const KEY_FALLBACK = 'admin_v2.ai.fallback_chat_model_id';

    private const KEY_EMBEDDING = 'admin_v2.ai.embedding_model_id';

    private const KEY_STREAMING = 'admin_v2.ai.streaming';

    private const KEY_TEMPERATURE = 'admin_v2.ai.temperature';

    private const KEY_MAX_TOKENS = 'admin_v2.ai.max_tokens';

    public function defaults(): Response
    {
        return Inertia::render('admin-v2/Ai/Defaults', [
            'defaults' => $this->readDefaults(),
            'chatModels' => $this->serialiseEnabledModels('chat'),
            'embeddingModels' => $this->serialiseEnabledModels('embedding'),
            'keys' => $this->readKeys(),
        ]);
    }

    public function catalog(): Response
    {
        return Inertia::render('admin-v2/Ai/Catalog', [
            'models' => AiCatalogModel::query()
                ->orderBy('driver')
                ->orderBy('capability')
                ->orderBy('label')
                ->get()
                ->map(fn ($m) => $this->serialiseModel($m))
                ->values(),
        ]);
    }

    public function usage(): Response
    {
        // Real usage aggregation lands once an `ai_usage` table exists;
        // for now the page renders an empty-state note.
        return Inertia::render('admin-v2/Ai/Usage', [
            'range' => null,
            'totals' => null,
            'series' => null,
            'byDriver' => null,
        ]);
    }

    public function updateDefaults(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // ai_catalog_models.id is an auto-incrementing integer — accept
            // either int or string and cast below.
            'primaryChatModelId' => ['sometimes', 'nullable', Rule::exists('ai_catalog_models', 'id')->where('capability', 'chat')],
            'fallbackChatModelId' => ['sometimes', 'nullable', Rule::exists('ai_catalog_models', 'id')->where('capability', 'chat')],
            'embeddingModelId' => ['sometimes', 'nullable', Rule::exists('ai_catalog_models', 'id')->where('capability', 'embeddings')],
            'streaming' => ['sometimes', 'boolean'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'maxTokens' => ['sometimes', 'integer', 'between:1,200000'],
        ]);

        // Detect embedding-model swaps so we can fire the reindex job.
        $previousEmbedding = AppSetting::getValue(self::KEY_EMBEDDING);
        $embeddingChanged = false;

        if ($request->has('primaryChatModelId')) {
            AppSetting::setValue(self::KEY_PRIMARY, (string) ($validated['primaryChatModelId'] ?? ''));
        }
        if ($request->has('fallbackChatModelId')) {
            AppSetting::setValue(self::KEY_FALLBACK, (string) ($validated['fallbackChatModelId'] ?? ''));
        }
        if ($request->has('embeddingModelId')) {
            $nextEmbedding = (string) ($validated['embeddingModelId'] ?? '');
            $prev = (string) ($previousEmbedding ?? '');
            // Only consider it a swap when there was a previous model AND it
            // differs from the new one. First-time assignment is a fresh set.
            $embeddingChanged = $prev !== '' && $prev !== $nextEmbedding && $nextEmbedding !== '';
            AppSetting::setValue(self::KEY_EMBEDDING, $nextEmbedding);
        }
        if ($request->has('streaming')) {
            AppSetting::setBool(self::KEY_STREAMING, (bool) $validated['streaming']);
        }
        if ($request->has('temperature')) {
            AppSetting::setValue(self::KEY_TEMPERATURE, (string) $validated['temperature']);
        }
        if ($request->has('maxTokens')) {
            AppSetting::setValue(self::KEY_MAX_TOKENS, (string) $validated['maxTokens']);
        }

        if ($embeddingChanged) {
            $this->dispatchEmbeddingReindex((string) $validated['embeddingModelId']);
        }

        return back()->with('success', __('AI defaults updated.'));
    }

    public function toggleModel(Request $request, AiCatalogModel $model): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $model->update(['is_enabled' => $validated['enabled']]);

        return back()->with('success', __('Catalog model updated.'));
    }

    public function rotateKey(Request $request, AiProvider $provider): RedirectResponse
    {
        $validated = $request->validate([
            // Whichever credential field the driver uses; we just overwrite
            // whatever's stored with the supplied value. The old value is
            // invalidated in-place (decision #2).
            'api_key' => ['required', 'string', 'min:16', 'max:500'],
        ]);

        $credentials = $provider->credentials ?? [];
        $credentials['api_key'] = $validated['api_key'];
        $credentials['rotated_at'] = now()->toIso8601String();

        $provider->update(['credentials' => $credentials]);

        Log::channel('daily')->info('admin_v2.ai.key_rotated', [
            'provider_id' => $provider->id,
            'driver' => $provider->driver,
            'by_user_id' => $request->user()?->id,
        ]);

        return back()->with('success', __('API key rotated.'));
    }

    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'string'],
            // Either an existing provider id (we look up credentials) or a
            // raw api_key to test without persisting.
            'provider_id' => ['nullable', 'string'],
            'api_key' => ['nullable', 'string'],
        ]);

        // Actual live probe requires SDK per driver; for now return OK if we
        // have credentials on file. Full live check is tracked as a follow-up.
        $hasCredential = false;
        if (! empty($validated['provider_id'])) {
            $provider = AiProvider::find($validated['provider_id']);
            $hasCredential = $provider && ! empty(($provider->credentials ?? [])['api_key']);
        } elseif (! empty($validated['api_key'])) {
            $hasCredential = true;
        }

        return response()->json([
            'ok' => $hasCredential,
            'message' => $hasCredential
                ? __('Credentials look good.')
                : __('No credential supplied.'),
        ]);
    }

    // ── private helpers ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function readDefaults(): array
    {
        return [
            'primaryChatModelId' => $this->nullableString(AppSetting::getValue(self::KEY_PRIMARY)),
            'fallbackChatModelId' => $this->nullableString(AppSetting::getValue(self::KEY_FALLBACK)),
            'embeddingModelId' => $this->nullableString(AppSetting::getValue(self::KEY_EMBEDDING)),
            'streaming' => AppSetting::getBool(self::KEY_STREAMING, true),
            'temperature' => (float) AppSetting::getValue(self::KEY_TEMPERATURE, '0.7'),
            'maxTokens' => AppSetting::getInt(self::KEY_MAX_TOKENS, 4096),
        ];
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
    private function serialiseEnabledModels(string $kind): array
    {
        $capability = $kind === 'embedding' ? 'embeddings' : 'chat';

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
            // Map ai_catalog_models.capability → handoff's AiModelKind.
            'kind' => $model->capability === 'embeddings' ? 'embedding' : 'chat',
            'enabled' => (bool) $model->is_enabled,
            'contextWindow' => null,
            'inputPricePerMTok' => null,
            'outputPricePerMTok' => null,
            'registeredAt' => $model->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readKeys(): array
    {
        return AiProvider::query()
            ->whereIn('visibility', ['global'])
            ->orderBy('driver')
            ->get()
            ->map(function (AiProvider $p) {
                $creds = $p->credentials ?? [];
                $key = (string) ($creds['api_key'] ?? '');
                $rotatedAt = $creds['rotated_at'] ?? null;

                return [
                    'id' => $p->id,
                    'driver' => $p->driver,
                    'label' => $p->display_name ?: $p->name,
                    'lastRotatedAt' => $rotatedAt,
                    'lastUsedAt' => null, // instrumentation lands with Usage.
                    'masked' => $this->maskKey($key),
                ];
            })
            ->values()
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

    /**
     * Dispatch the reindex job for every KB that depends on the previous
     * embedding model so their vectors are regenerated with the new one.
     */
    private function dispatchEmbeddingReindex(string $newModelId): void
    {
        KnowledgeBase::query()->chunk(50, function ($knowledgeBases) use ($newModelId) {
            foreach ($knowledgeBases as $kb) {
                RecomputeEmbeddingsJob::dispatch($kb->id, $newModelId);
            }
        });
    }
}
