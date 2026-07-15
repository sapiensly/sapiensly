<?php

namespace App\Mcp\Tools\Build;

use App\Jobs\ExpressDashboardJob;
use App\Mcp\Tools\SapiensTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\PipelineRun;
use App\Models\User;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Build a whole dashboard from ONE natural-language prompt via the Express pipeline — the same L4 autonomous builder the in-app "describe your dashboard" flow runs. From the prompt it chooses the right data (your connected integration\'s tools or your own records), profiles the row shapes, suggests a KPI/chart spec, compiles it and applies it as a new app version, closing with an honest report (what it built, what it substituted, what the source could not answer). Runs ASYNCHRONOUSLY on the background queue and returns immediately with a run_id + placeholder message_id; a synchronous build (often 40-120s, up to minutes for a broad board) would exceed the MCP transport timeout and die mid-build. POLL get_builder_conversation (by conversation_id) until the assistant message leaves status="streaming" — then read its report, and read_manifest / list_app_versions to see the built dashboard. Create the target app first with create_app (an empty placeholder is fine); Express fills it in. Contrast with propose_change (you author the manifest yourself) and continue_builder_conversation (an interactive, tool-calling builder turn); this is the one-shot, spec-driven dashboard factory.')]
class BuildExpressDashboardTool extends SapiensTool
{
    protected const ABILITY = 'apps:build';

    public function handle(Request $request): Response
    {
        if (! (bool) config('express.enabled')) {
            return Response::error('The Express dashboard pipeline is disabled on this instance (config express.enabled).');
        }

        $validated = $request->validate([
            'app_slug' => ['nullable', 'string'],
            'conversation_id' => ['nullable', 'string'],
            'prompt' => ['required', 'string', 'max:5000'],
            'model' => ['nullable', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (($validated['conversation_id'] ?? null) === null && ($validated['app_slug'] ?? null) === null) {
            return Response::error('Provide either app_slug (the app Express should build into) or conversation_id.');
        }

        $prompt = trim($validated['prompt']);
        if ($prompt === '') {
            return Response::error('The prompt is empty.');
        }

        [$conversation, $app, $error] = $this->resolve($validated, $user);
        if ($error !== null) {
            return Response::error($error);
        }

        // Mirror the in-app Express launcher (AppBuilderController::startExpressRun):
        // persist the user turn + a streaming assistant placeholder, open a
        // PipelineRun, then hand the build to the background queue. A new turn
        // re-arms the machinery, so clear any standing stop flag first.
        app(BuilderCancellation::class)->clear($conversation);

        BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $prompt,
            'status' => 'none',
        ]);
        $placeholder = BuilderMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'streaming',
        ]);

        $run = PipelineRun::create([
            'app_id' => $app->id,
            'conversation_id' => $conversation->id,
            'kind' => 'dashboard_express',
            'prompt' => $prompt,
        ]);

        ExpressDashboardJob::dispatch($placeholder->id, $run->id, $prompt, $validated['model'] ?? null);

        return Response::json([
            'run_id' => $run->id,
            'conversation_id' => $conversation->id,
            'app_slug' => $app->slug,
            'message_id' => $placeholder->id,
            'status' => 'streaming',
            'message' => 'Express build started in the background. Poll get_builder_conversation'
                ." (conversation_id='{$conversation->id}') until this message leaves status='streaming' —"
                .' then read its report, and read_manifest / list_app_versions to see the built dashboard.'
                .' Builds take ~40-120s (longer for a broad board).',
        ]);
    }

    /**
     * Resolve the target conversation + app, creating a fresh conversation for
     * the app when none exists — the same resolution as the interactive builder
     * tool, so a caller can pass either a conversation_id or just an app_slug.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: BuilderConversation|null, 1: App|null, 2: string|null}
     */
    private function resolve(array $validated, User $user): array
    {
        if (($validated['conversation_id'] ?? null) !== null) {
            $conversation = BuilderConversation::query()->find($validated['conversation_id']);
            if ($conversation === null) {
                return [null, null, "No builder conversation '{$validated['conversation_id']}' is visible to you."];
            }

            $app = App::query()->forAccountContext($user)->where('id', $conversation->app_id)->first();
            if ($app === null) {
                return [null, null, "No builder conversation '{$validated['conversation_id']}' is visible to you."];
            }

            return [$conversation, $app, null];
        }

        try {
            $app = $this->resolveApp($validated['app_slug'], $user);
        } catch (ModelNotFoundException) {
            return [null, null, "No app named '{$validated['app_slug']}' is visible to you."];
        }

        $conversation = BuilderConversation::query()
            ->where('app_id', $app->id)
            ->orderByRaw("status = 'active' DESC")
            ->orderByDesc('created_at')
            ->first();

        if ($conversation === null) {
            $conversation = app(BuilderAiService::class)->startConversation($app, $user);
        }

        return [$conversation, $app, null];
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app_slug' => $schema->string()
                ->description('The app Express should build the dashboard into. Create it first with create_app (an empty placeholder is fine). Either this or conversation_id is required.'),
            'conversation_id' => $schema->string()
                ->description("Instead of app_slug, target an existing builder conversation (from list_builder_conversations); Express builds into that conversation's app and its most recent one is reused when app_slug is given."),
            'prompt' => $schema->string()
                ->description('What the dashboard should show, in plain language — the audience and the questions it answers. Express picks the data source, KPIs and charts from this. E.g. "An operations view of delivery performance: on-time delivery and shipping SLA trends, customer sentiment, and the top incident causes."')
                ->required(),
            'model' => $schema->string()
                ->description('Optional model override for the pipeline gates. Omit to let Express choose (economy mode uses deterministic defaults when the fit is unambiguous).'),
        ];
    }
}
