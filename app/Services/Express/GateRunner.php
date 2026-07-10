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
        ?ExpressContext $context = null,
        ?string $stableContext = null,
    ): array {
        // Circuit-breaker: a provider that already hung this run won't answer
        // the next gate either — asking burns another full 45s window for the
        // same fallback. Skip straight to the default (0s) and record why.
        if ($context !== null && $context->providerHung) {
            $output = is_callable($default) ? $default() : $default;
            $run->recordGate($name, [
                'model' => null,
                'latency_ms' => 0,
                'fallback_used' => true,
                'tokens' => null,
                'error' => 'skipped: provider hung earlier in this run',
            ]);
            Log::info('Express gate skipped — provider already hung this run', [
                'run_id' => $run->id, 'gate' => $name,
            ]);

            return ['output' => $output, 'fallback_used' => true, 'model' => null];
        }

        $startedAt = microtime(true);
        $model = null;
        $output = null;
        $error = null;
        $tokens = null;
        $salvaged = false;

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

            $agent = new ExpressGateAgent($instructions, $schema, $stableContext);

            $attempts = 0;
            while ($attempts < 2 && $output === null) {
                $attempts++;
                try {
                    $response = $agent->prompt($prompt, provider: $provider, model: $model, timeout: self::TIMEOUT_SECONDS);
                    $decoded = $response instanceof Arrayable ? $response->toArray() : null;
                    if (is_array($decoded) && $decoded !== []) {
                        $output = $decoded;
                    } elseif (($output = $this->decodeLenient($response->text ?? null)) !== null) {
                        // A weak model often returns the RIGHT JSON in the
                        // WRONG wrapper — ```json fences, a prose preamble,
                        // reasoning spill — and the SDK's strict decode of the
                        // message text silently yields []. Observed on GLM:
                        // gates billed 1.2k output tokens and still "didn't
                        // answer" (telemetry error:null). The raw text is
                        // still on the response — salvage the object from it
                        // before burning a retry or the default.
                        $salvaged = true;
                    }
                    if (isset($response->usage)) {
                        $tokens = [
                            'in' => $response->usage->promptTokens,
                            'out' => $response->usage->completionTokens,
                        ];
                    }
                    try {
                        $this->usage->record(
                            'express', $model, $user, $user->organization_id, $response->usage ?? null,
                            appId: $run->app_id, conversationId: $run->conversation_id,
                        );
                    } catch (\Throwable) {
                        // Usage accounting is best-effort.
                    }
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    // A TIMEOUT means the provider is hung — retrying burns
                    // another full timeout window for the same outcome
                    // (observed: 2×45s = 90s wasted on one gate). Fall straight
                    // to the deterministic default AND trip the run-wide breaker
                    // so the remaining gates skip the model entirely.
                    if (str_contains($error, 'timed out') || str_contains($error, 'cURL error 28')) {
                        $context?->markProviderHung();
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

        $telemetry = [
            'model' => $model,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'fallback_used' => $fallback,
            'tokens' => $tokens,
            'error' => $fallback ? $error : null,
        ];
        if ($salvaged) {
            $telemetry['salvaged'] = true;
        }
        $run->recordGate($name, $telemetry);

        return ['output' => $output, 'fallback_used' => $fallback, 'model' => $model];
    }

    /**
     * Salvage a JSON object from model text that failed the strict decode: the
     * slice from the first '{' to the last '}' — which unwraps ```json fences,
     * prose preambles and trailing chatter alike — decoded strictly. Null when
     * no such slice exists or it still isn't valid JSON.
     *
     * @return array<string, mixed>|null
     */
    private function decodeLenient(?string $text): ?array
    {
        $text = trim((string) $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }
}
