<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\User;
use App\Services\Apps\BuildCritic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * The closing review, as a builder tool. Call it before telling anyone the app
 * is done: it reads the app you just built against the request that produced it
 * and reports what is missing and what was invented.
 *
 * It exists because the failure it catches is invisible from the inside. A turn
 * that runs out of road narrates success — "el trabajo fundamental está hecho"
 * after five rejected patches, "✅ Campos de captura de campo" over fields wired
 * into the wrong page. Both apps validated clean. Only reading the result
 * against the request finds it.
 */
class CritiqueBuildTool implements Tool
{
    public function __construct(
        private App $appModel,
        private BuildCritic $critic,
        private ?User $user = null,
        private ?string $conversationId = null,
        /** Reads the running turn's draft so it judges what you JUST authored. */
        private ?ProposeChangeTool $proposeTool = null,
    ) {}

    public function name(): string
    {
        return 'critique_build';
    }

    public function description(): string
    {
        return <<<'DESC'
Review the app you built against what was asked, BEFORE you report it as done.
Pass `request` — the user's ask, in their own words, not your summary of it.

Returns `{complete, missing[], unrequested[], summary}`. `missing` is what the
request asked for and the app does not have; `unrequested` is subject matter the
app has that nobody asked for. Fix everything in `missing` with propose_change
and re-call. `unrequested` is a judgement call: remove it or say why it belongs.

Read `critic`: 'ok' means it ran, 'failed' means no model produced a verdict —
which is NOT approval, and is retryable.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request' => $schema->string()
                ->description("The user's request in their own words — quote it, do not paraphrase. Paraphrasing hides the very gap this looks for.")
                ->required(),
            'model' => $schema->string()
                ->description('Optional catalog model id to review with. Omit to use the configured Build Critic.'),
        ];
    }

    public function handle(Request $request): string
    {
        $user = $this->user ?? $this->appModel->user;

        if ($user === null) {
            return json_encode([
                'critic' => 'skipped',
                'reason' => 'No user in context to attribute the review to.',
            ], JSON_THROW_ON_ERROR);
        }

        $verdict = $this->critic->critique(
            $this->appModel->fresh() ?? $this->appModel,
            (string) ($request->all()['request'] ?? ''),
            $user,
            $request->all()['model'] ?? null,
            $this->conversationId,
            $this->proposeTool?->currentManifest(),
        );

        $this->stampWhenClean($verdict);

        return json_encode($verdict, JSON_THROW_ON_ERROR);
    }

    /**
     * A first clean verdict retires the review rail for this conversation.
     *
     * Only `complete` — an unrequested finding is a judgement call for a person,
     * not something to hold the build hostage over. And only a verdict that
     * actually RAN: 'failed' means nobody looked, which must never stamp, or the
     * rail is defeated by the one outcome it exists to survive.
     *
     * @param  array<string, mixed>  $verdict
     */
    private function stampWhenClean(array $verdict): void
    {
        if (($verdict['critic'] ?? null) !== 'ok' || ($verdict['complete'] ?? false) !== true) {
            return;
        }

        if ($this->conversationId === null) {
            return;
        }

        BuilderConversation::query()
            ->whereKey($this->conversationId)
            ->whereNull('build_reviewed_at')
            ->update(['build_reviewed_at' => now()]);
    }
}
