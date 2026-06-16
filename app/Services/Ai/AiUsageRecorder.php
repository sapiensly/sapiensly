<?php

namespace App\Services\Ai;

use App\Models\AiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Records one AI model call into ai_usage_events: tokens + computed cost, the
 * module that made the call, and whether it ran on the org's OWN provider key
 * (`own`) or a platform/system key (`system`). The single seam every AI call
 * site funnels usage through. Best-effort: it must NEVER break the user-facing
 * call, so all failures are swallowed + logged.
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
    ): void {
        try {
            $usage ??= new Usage;

            AiUsageEvent::create([
                'organization_id' => $organizationId ?? $user?->organization_id,
                'user_id' => $user?->id,
                'module' => $module,
                'driver' => $this->sources->driver($model),
                'model' => $model,
                'source' => $this->sources->source($model, $user),
                'input_tokens' => $usage->promptTokens,
                'output_tokens' => $usage->completionTokens,
                'cache_read_tokens' => $usage->cacheReadInputTokens,
                'cache_write_tokens' => $usage->cacheWriteInputTokens,
                'reasoning_tokens' => $usage->reasoningTokens,
                'cost' => $this->pricing->costFor($model, $usage),
                'estimated' => $estimated,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AI usage recording failed (continuing)', [
                'module' => $module,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
