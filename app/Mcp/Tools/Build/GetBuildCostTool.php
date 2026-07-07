<?php

namespace App\Mcp\Tools\Build;

use App\Mcp\Tools\SapiensTool;
use App\Models\User;
use App\Services\Ai\AiUsageReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('The real AI cost of building an app: every model call tagged with this app — across the builder and the Express dashboard pipeline — rolled up into total cost, calls and tokens, with a per-model, per-conversation and per-service (Apps/Express) split. Pass app_slug (whole app, every build) or add conversation_id to scope to one build session. Cost covers both own-key and platform-paid calls. Only calls made after usage tagging shipped are attributed.')]
class GetBuildCostTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app_slug' => ['required', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

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

        return Response::json($report);
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
        ];
    }
}
