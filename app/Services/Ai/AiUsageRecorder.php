<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\SystemAiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Records one AI model call's tokens + computed cost. Two destinations, by who
 * pays:
 *   - The per-org meter: tenant.ai_usage_events (RLS-scoped) — written for every
 *     call that has tenant context, own or system. Drives the org dashboard and
 *     the spend guard.
 *   - The platform meter: platform.system_ai_usage_events — written for every
 *     `system` call (the platform's key paid), independent of tenant context, so
 *     even an unattributable system call is captured. Drives the admin dashboard.
 *
 * The single seam every AI call site funnels usage through. Best-effort: it must
 * NEVER break the user-facing call, so each write's failures are swallowed +
 * logged independently (a tenant-RLS rejection must not lose the platform row).
 */
class AiUsageRecorder
{
    public function __construct(
        private readonly AiPricing $pricing,
        private readonly AiSourceResolver $sources,
    ) {}

    public function record(
        string $module,
        string $model,
        ?User $user,
        ?string $organizationId,
        ?Usage $usage,
        string $status = 'success',
        bool $estimated = false,
        ?float $cost = null,
    ): void {
        try {
            $usage ??= new Usage;
            $source = $this->sources->source($model, $user);

            $attributes = [
                'organization_id' => $organizationId ?? $user?->organization_id,
                'user_id' => $user?->id,
                'module' => $module,
                'driver' => $this->sources->driver($model),
                'model' => $model,
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                // A precomputed cost (e.g. per-page OCR) overrides token pricing.
                'cost' => $cost ?? $this->pricing->costFor($model, $usage),
                'estimated' => $estimated,
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            $this->logFailure('prepare', $module, $model, $e);

            return;
        }

        // Per-org meter (RLS): records the tenant's consumption. Skipped + harmless
        // for a context-less system call, where RLS would reject it anyway.
        try {
            AiUsageEvent::create($attributes + ['source' => $source]);
        } catch (\Throwable $e) {
            $this->logFailure('tenant', $module, $model, $e);
        }

        // Platform meter (no RLS): the platform pays for `system` calls, so record
        // them in the control-plane ledger regardless of tenant context.
        if ($source === 'system') {
            try {
                SystemAiUsageEvent::create($attributes);
            } catch (\Throwable $e) {
                $this->logFailure('platform', $module, $model, $e);
            }
        }
    }

    private function logFailure(string $meter, string $module, string $model, \Throwable $e): void
    {
        Log::warning('AI usage recording failed (continuing)', [
            'meter' => $meter,
            'module' => $module,
            'model' => $model,
            'error' => $e->getMessage(),
        ]);
    }
}
