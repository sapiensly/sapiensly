<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The real AI cost of building an app: every model call tagged with this app — across the builder and the Express dashboard pipeline — rolled up into total cost, calls and tokens, with a per-model, per-conversation and per-service (Apps/Express) split. Pass app_slug (whole app, every build) or add conversation_id to scope to one build session. Cost covers both own-key and platform-paid calls. Only calls made after usage tagging shipped are attributed. Set include_gates:true to also see, for each Express run, which MODEL ran each gate (fit_check, spec_overrides, voice_insights, verify…) with its latency and whether it fell back — the forensic view for "why did this build bill the wrong model?".')]
class GetBuildCostTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // Not a strict `boolean` rule: MCP clients often send the flag as
            // the string "true"/"false", which Laravel's boolean rule rejects.
            // Accept anything and coerce below.
            'include_gates' => ['sometimes'],
        ]);
        $includeGates = filter_var($validated['include_gates'] ?? false, FILTER_VALIDATE_BOOLEAN);

        /** @var User $user */
        $user = $request->user();

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return Response::error("No app named '{$validated['app_slug']}' is visible to you.");
        }

        // RLS scopes ai_usage_events to the caller's org; the app is already
        // visibility-checked above, so this only ever reads the caller's own
        // build cost.
        $report = app(AiUsageReport::class)->forApp(
            $app->id,
            $validated['conversation_id'] ?? null,
            $validated['days'] ?? 90,
        );

        $report['app_slug'] = $app->slug;

        if (($report['totals']['calls'] ?? 0) === 0) {
            $report['note'] = 'No tagged AI calls found for this app in the window. Usage attribution began when the app_id/conversation_id tagging shipped — earlier builds are unattributed.';
        }

        if ($includeGates) {
            $report['pipeline_runs'] = $this->gateTelemetry($app->id, $validated['conversation_id'] ?? null);
        }

        return Response::json($report);
    }

    /**
     * Per-gate model telemetry for the app's Express runs (RLS-scoped) — which
     * MODEL answered each gate, its latency, and whether it fell back to the
     * deterministic default. Only Express builds have pipeline runs; agentic
     * builds return an empty list.
     *
     * @return list<array<string, mixed>>
     */
    private function gateTelemetry(string $appId, ?string $conversationId): array
    {
        $query = PipelineRun::query()
            ->where('app_id', $appId)
            ->latest();
        if ($conversationId !== null) {
            $query->where('conversation_id', $conversationId);
        }

        return $query->limit(20)->get()->map(function (PipelineRun $run): array {
            $gates = collect(is_array($run->gates) ? $run->gates : [])
                ->map(fn ($g): array => array_filter([
                    'model' => is_array($g) ? ($g['model'] ?? null) : null,
                    'latency_ms' => is_array($g) ? ($g['latency_ms'] ?? null) : null,
                    'fallback_used' => is_array($g) ? ($g['fallback_used'] ?? null) : null,
                    'tokens' => is_array($g) ? ($g['tokens'] ?? null) : null,
                    'error' => is_array($g) ? ($g['error'] ?? null) : null,
                    // Forensic extras individual gates record: economy skips,
                    // salvaged decodes, the interpreter's translation and
                    // whether it was adopted. Projecting these away made a
                    // prod audit conclude "old code" from their absence.
                    'economy' => is_array($g) ? ($g['economy'] ?? null) : null,
                    'salvaged' => is_array($g) ? ($g['salvaged'] ?? null) : null,
                    'translation' => is_array($g) ? ($g['translation'] ?? null) : null,
                    'adopted' => is_array($g) ? ($g['adopted'] ?? null) : null,
                    'applied' => is_array($g) ? ($g['applied'] ?? null) : null,
                    'rejections' => is_array($g) ? ($g['rejections'] ?? null) : null,
                    'fixes' => is_array($g) ? ($g['fixes'] ?? null) : null,
                ], fn ($v) => $v !== null))
                ->all();

            return array_filter([
                'run_id' => $run->id,
                'conversation_id' => $run->conversation_id,
                'status' => $run->status,
                'created_at' => $run->created_at?->toIso8601String(),
                'gates' => $gates,
            ], fn ($v) => $v !== null && $v !== []);
        })->all();
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('Slug of the app whose build cost to total.'),
            'conversation_id' => $schema->string()
                ->description('Optional: scope to one builder conversation (from list_builder_conversations) instead of the whole app.'),
            'days' => $schema->integer()
                ->description('Look-back window in days (default 90, max 365) — a bound on how far back to sum calls.'),
            'include_gates' => $schema->boolean()
                ->description('Also return each Express run\'s per-gate model + latency + fallback telemetry (pipeline_runs.gates) — the forensic view of which model ran each gate.'),
        ];
    }
}
