<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use App\Models\AppSetting;
use RuntimeException;
use Throwable;

/**
 * Per-category default AI models. Each category — a product module (chat, the
 * automatic short/large summary helpers, app builder, flows, chatbots) or a
 * specialized capability handler (embeddings, coding, OCR, image generation,
 * vision, transcription, speech, reranking) — has a configurable PRIMARY model
 * and a FALLBACK, set on the admin AI > Defaults screen and stored in
 * `app_settings` under `admin_v2.ai.{module}.{primary|fallback}`. Each category
 * maps to a model {@see self::CAPABILITY} that constrains which catalog models
 * are eligible.
 *
 * The stored value is an `ai_catalog_models` id (what the admin select submits);
 * the runtime needs the model string. So this service speaks two shapes:
 * {@see self::primaryId()}/{@see self::fallbackId()} return the stored catalog id
 * (for the admin UI), while {@see self::primary()}/{@see self::fallback()} resolve
 * it to the model string (for the modules).
 *
 * Resolution for a request: an explicit model (an agent/entity override) wins,
 * then the module primary, then the fallback, then a hard default — so a request
 * is never left without a model. On an LLM error, callers use
 * {@see self::withFallback()} to advance to the next candidate.
 */
class AiDefaults
{
    /**
     * Configurable default categories. The first group are product modules (all
     * chat models); the second are specialized capability handlers an agent can
     * hand off to (image generation, OCR, transcription, TTS, reranking, …).
     *
     * @var list<string>
     */
    public const MODULES = [
        // Product modules (chat capability). `landing_builder` is OPTIONAL by
        // design: when unset the builder keeps its normal model, and when set
        // the builder switches to it as soon as an app is tagged as a landing
        // (landings live or die on design, so they often warrant a stronger
        // model than the cheap-and-fast general builder default).
        'chat', 'summary_short', 'summary_large', 'builder', 'landing_builder', 'flows', 'chatbots',
        // Specialized capability handlers.
        'embeddings', 'coding', 'ocr_pdf', 'image_vision', 'image_generation',
        'audio_recognition', 'speech_generation', 'reranking',
    ];

    /**
     * The specialized capability categories (everything beyond the chat product
     * modules). These are what an agent can hand off to.
     *
     * @var list<string>
     */
    public const CAPABILITY_MODULES = [
        'embeddings', 'coding', 'ocr_pdf', 'image_vision', 'image_generation',
        'audio_recognition', 'speech_generation', 'reranking',
    ];

    /**
     * Maps each category to the `ai_catalog_models.capability` whose models are
     * eligible for it. Drives both the admin picker filtering and validation.
     *
     * @var array<string, string>
     */
    public const CAPABILITY = [
        'chat' => 'chat',
        'summary_short' => 'chat',
        'summary_large' => 'chat',
        'builder' => 'chat',
        'landing_builder' => 'chat',
        'flows' => 'chat',
        'chatbots' => 'chat',
        'embeddings' => 'embeddings',
        'coding' => 'chat',
        'ocr_pdf' => 'vision',
        'image_vision' => 'vision',
        'image_generation' => 'image',
        'audio_recognition' => 'transcription',
        'speech_generation' => 'speech',
        'reranking' => 'rerank',
    ];

    /**
     * Last-resort model per capability when nothing is configured. Only `chat`
     * has a safe universal fallback; specialized capabilities resolve to null
     * (the consumer reports "not configured" rather than calling a wrong model).
     *
     * @var array<string, string>
     */
    public const HARD_DEFAULTS = [
        'chat' => 'claude-haiku-4-5-20251001',
    ];

    /**
     * Last-resort model for chat modules. Kept for the chat call sites.
     */
    public const HARD_DEFAULT = 'claude-haiku-4-5-20251001';

    /** The capability whose models are eligible for a category. */
    public function capabilityFor(string $module): string
    {
        return self::CAPABILITY[$module] ?? 'chat';
    }

    /** The last-resort model string for a category, or null when none is safe. */
    public function hardDefaultFor(string $module): ?string
    {
        return self::HARD_DEFAULTS[$this->capabilityFor($module)] ?? null;
    }

    public function key(string $module, string $slot): string
    {
        return "admin_v2.ai.{$module}.{$slot}";
    }

    /** The stored catalog id for a slot (for the admin UI), or null when unset. */
    public function catalogId(string $module, string $slot): ?string
    {
        $value = trim((string) AppSetting::getValue($this->key($module, $slot), ''));

        return $value !== '' ? $value : null;
    }

    public function primaryId(string $module): ?string
    {
        return $this->catalogId($module, 'primary');
    }

    public function fallbackId(string $module): ?string
    {
        return $this->catalogId($module, 'fallback');
    }

    /** Persist a slot's catalog id (empty string clears it). */
    public function setCatalogId(string $module, string $slot, ?string $catalogId): void
    {
        AppSetting::setValue($this->key($module, $slot), (string) ($catalogId ?? ''));
    }

    /** The primary model STRING for a module (for the runtime), or null when unset. */
    public function primary(string $module): ?string
    {
        return $this->modelStringFor($this->primaryId($module));
    }

    public function fallback(string $module): ?string
    {
        return $this->modelStringFor($this->fallbackId($module));
    }

    /**
     * The single model string to use (no error-retry): explicit → primary →
     * fallback → hard default. Throws when a specialized capability category has
     * nothing configured (no safe hard default) — callers that tolerate an
     * unconfigured handler should use {@see self::modelOrNull()}.
     */
    public function model(string $module, ?string $explicit = null): string
    {
        $candidate = $this->candidates($module, $explicit)[0] ?? null;

        if ($candidate === null) {
            throw new RuntimeException("No model is configured for '{$module}'. Set one in admin AI > Defaults.");
        }

        return $candidate;
    }

    /**
     * Like {@see self::model()} but returns null instead of throwing when nothing
     * is configured. Use for specialized capabilities that may be unconfigured.
     */
    public function modelOrNull(string $module, ?string $explicit = null): ?string
    {
        return $this->candidates($module, $explicit)[0] ?? null;
    }

    /**
     * The ordered, de-duplicated candidate model strings for a module. The final
     * fallback is the category's capability hard default (null for specialized
     * capabilities, so they yield an empty list when unconfigured).
     *
     * @return list<string>
     */
    public function candidates(string $module, ?string $explicit = null): array
    {
        $explicit = $explicit !== null && trim($explicit) !== '' ? trim($explicit) : null;

        return array_values(array_unique(array_filter([
            $explicit,
            $this->primary($module),
            $this->fallback($module),
            $this->hardDefaultFor($module),
        ])));
    }

    /**
     * Run `$callback($model)` against each candidate in order; if it throws,
     * advance to the next (primary → fallback → hard default). Returns the first
     * success, or rethrows the last error if every candidate fails.
     *
     * @template T
     *
     * @param  callable(string):T  $callback
     * @return T
     */
    public function withFallback(string $module, callable $callback, ?string $explicit = null): mixed
    {
        $last = null;

        foreach ($this->candidates($module, $explicit) as $model) {
            try {
                return $callback($model);
            } catch (Throwable $e) {
                $last = $e;
            }
        }

        throw $last ?? new RuntimeException("No model is available for module '{$module}'.");
    }

    private function modelStringFor(?string $catalogId): ?string
    {
        if ($catalogId === null) {
            return null;
        }

        $modelString = AiCatalogModel::query()->whereKey($catalogId)->value('model_id');

        return $modelString !== null && $modelString !== '' ? (string) $modelString : null;
    }
}
