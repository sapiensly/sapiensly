<?php

namespace App\Services\Express;

use App\Ai\ExpressGateAgent;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Enums\Lab;

/**
 * Runs ONE Express gate: a single structured prompt with no tools, a hard
 * timeout, one retry, and a deterministic default when the model can't answer.
 * A gate failure NEVER fails the pipeline — it degrades to the default and is
 * recorded as such, so the build always finishes and the telemetry says
 * exactly which decisions the model actually made.
 */
class GateRunner
{
    private const TIMEOUT_SECONDS = 45;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
        private readonly AiUsageRecorder $usage,
    ) {}

    /**
     * @param  Closure(JsonSchema): array<string, mixed>  $schema
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $default
     * @return array{output: array<string, mixed>, fallback_used: bool, model: ?string}
     */
    public function run(
        PipelineRun $run,
        string $name,
        string $instructions,
        string $prompt,
        Closure $schema,
        array|Closure $default,
        User $user,
        ?string $modelOverride = null,
    ): array {
        $startedAt = microtime(true);
        $model = null;
        $output = null;
        $error = null;
        $tokens = null;

        try {
            // Resolution is best-effort: a missing provider config surfaces as
            // a prompt failure (→ fallback), not as skipping the attempt.
            $provider = Lab::Anthropic;
            try {
                $model = $this->aiDefaults->model('builder', $modelOverride);
                $this->providers->applyRuntimeConfig($user);
                $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;
            } catch (\Throwable) {
                $model = $modelOverride;
            }

            $agent = new ExpressGateAgent($instructions, $schema);

            $attempts = 0;
            while ($attempts < 2 && $output === null) {
                $attempts++;
                try {
                    $response = $agent->prompt($prompt, provider: $provider, model: $model, timeout: self::TIMEOUT_SECONDS);
                    $decoded = $response instanceof Arrayable ? $response->toArray() : null;
                    if (is_array($decoded) && $decoded !== []) {
                        $output = $decoded;
                    }
                    if (isset($response->usage)) {
                        $tokens = [
                            'in' => $response->usage->promptTokens,
                            'out' => $response->usage->completionTokens,
                        ];
                    }
                    try {
                        $this->usage->record('express', $model, $user, $user->organization_id, $response->usage ?? null);
                    } catch (\Throwable) {
                        // Usage accounting is best-effort.
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    // A TIMEOUT means the provider is hung — retrying burns
                    // another full timeout window for the same outcome
                    // (observed: 2×45s = 90s wasted on one gate). Fall straight
                    // to the deterministic default instead.
                    if (str_contains($error, 'timed out') || str_contains($error, 'cURL error 28')) {
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $fallback = $output === null;
        if ($fallback) {
            $output = is_callable($default) ? $default() : $default;
            Log::info('Express gate fell back to its default', [
                'run_id' => $run->id, 'gate' => $name, 'error' => $error,
            ]);
        }

        $run->recordGate($name, [
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'fallback_used' => $fallback,
            'tokens' => $tokens,
            'error' => $fallback ? $error : null,
        ]);

        return ['output' => $output, 'fallback_used' => $fallback, 'model' => $model];
    }
}
