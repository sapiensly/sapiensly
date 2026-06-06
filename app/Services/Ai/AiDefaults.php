<?php

namespace App\Services\Ai;

use App\Models\AiCatalogModel;
use App\Models\AppSetting;
use RuntimeException;
use Throwable;

/**
 * Per-module default AI models. Each product module (chat, app builder, flows,
 * chatbots) has a configurable PRIMARY model and a FALLBACK, set on the admin
 * AI > Defaults screen and stored in `app_settings` under
 * `admin_v2.ai.{module}.{primary|fallback}`.
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
    /** @var list<string> */
    public const MODULES = ['chat', 'builder', 'flows', 'chatbots'];

    /**
     * Last-resort model when nothing is configured for a module. Mirrors the
     * constants the modules carried before defaults were wired in.
     */
    public const HARD_DEFAULT = 'claude-haiku-4-5-20251001';

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
     * fallback → hard default.
     */
    public function model(string $module, ?string $explicit = null): string
    {
        return $this->candidates($module, $explicit)[0];
    }

    /**
     * The ordered, de-duplicated candidate model strings for a module.
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
            self::HARD_DEFAULT,
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
