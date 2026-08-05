<?php

namespace App\Services\Apps;

use App\Ai\ExpressGateAgent;
use App\Models\App;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\Ai\AiUsageRecorder;
use App\Services\AiProviderService;
use App\Services\Apps\Docs\AppDocs;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Enums\Lab;

/**
 * The closing review of an app build: does the finished app answer what was
 * asked, and does it contain anything nobody asked for?
 *
 * Both directions come from the same measured failure. A builder that runs out
 * of road does not stop — it narrates success. Observed twice on the same brief:
 * one build abandoned an edit after five rejected patches and closed with
 * "el trabajo fundamental está hecho"; another reported "✅ Campos de captura de
 * campo" over fields it had wired into the wrong page. Neither is detectable by
 * the validator, because both apps were structurally valid. The only thing that
 * catches them is reading the result against the request.
 *
 * The other direction is the one nobody looks for. The same builds invented a
 * "Punto de venta" page — a retail cart, in a field-service app — which no rule
 * flags because a page is a legal page. Something built without being asked is
 * not free: it is surface a person has to understand, maintain and eventually
 * delete.
 *
 * Cheap by construction. It reads the TECHNICAL SHEET rather than the manifest
 * (ids already joined to names, a fraction of the tokens) and answers ONCE — so
 * putting a stronger model behind `build_critic` costs a rounding error next to
 * running the whole build on it.
 */
class BuildCritic
{
    /** A critique is one bounded question; a long deliberation here is a bug. */
    private const TIMEOUT_SECONDS = 90;

    /** Primary + one retry. A third pass has never changed a verdict. */
    private const MAX_ATTEMPTS = 2;

    private const INSTRUCTIONS = <<<'TXT'
    You review a finished app against the request that produced it. You are the
    last reader before a person is told it is done, and that person will believe
    you.

    You are given the REQUEST (what was asked, in the requester's own words) and
    the TECHNICAL SHEET of the app that was built (objects, fields with types,
    pages with their blocks, actions, workflows, permissions).

    Report two things, and be specific enough to act on:

    MISSING — something the request asks for that the app does not have. Name
    what was asked and what you looked for. A request for "a signature captured
    with a finger" is NOT satisfied by a text field called `firma`; a request for
    "scannable codes" is not satisfied by a plain string. Judge the app as built,
    not as described by its own field names.

    UNREQUESTED — something the app has that the request never asked for and that
    does not follow from it. Ordinary scaffolding is NOT unrequested: list and
    detail pages, create/edit forms, a record's own id, timestamps, counts of a
    relation. What you are looking for is invented SUBJECT MATTER — a whole page,
    object or workflow belonging to some other product. When in doubt, leave it
    out: a false accusation here costs more than a missed one.

    Say complete=true only when MISSING is empty. Being thorough is the job;
    being agreeable is not. If the app answers the request, say so plainly and
    report nothing.
    TXT;

    public function __construct(
        private readonly AiDefaults $aiDefaults,
        private readonly AiProviderService $providers,
        private readonly AppDocs $docs,
    ) {}

    /**
     * Review an app against the request that produced it.
     *
     * Returns `critic: 'ok'|'failed'` so a caller can tell "nothing to report"
     * from "nobody looked" — the distinction the landing gate learned the hard
     * way, where a timed-out director silently counted as approval.
     *
     * @return array<string, mixed>
     */
    public function critique(
        App $app,
        string $request,
        User $user,
        ?string $explicitModel = null,
        ?string $conversationId = null,
    ): array {
        $request = trim($request);
        if ($request === '') {
            return [
                'critic' => 'skipped',
                'reason' => 'No request text to review the app against.',
            ];
        }

        $sheet = $this->docs->of($app, 'technical')->toMarkdown();

        foreach ($this->criticCandidates($explicitModel) as $model) {
            $verdict = $this->attempt($request, $sheet, $user, $model, $app, $conversationId);
            if ($verdict !== null) {
                return $verdict + ['critic' => 'ok', 'model' => $model];
            }
        }

        // Never reported as a clean review: a caller that cannot tell these
        // apart will ship on the strength of a critique that never ran.
        return [
            'critic' => 'failed',
            'reason' => 'No critic model produced a usable verdict. Retryable.',
        ];
    }

    /**
     * The models to try, best first: an explicit override, then the configured
     * critic, then its fallback (the retry when the primary pass fails), then
     * the builder's own chain so an install that configures nothing still gets
     * reviewed.
     *
     * @return list<string>
     */
    public function criticCandidates(?string $explicit = null): array
    {
        $explicit = $explicit !== null && trim($explicit) !== '' ? trim($explicit) : null;

        $chain = array_values(array_unique(array_filter([
            $explicit,
            $this->aiDefaults->primary('build_critic'),
            $this->aiDefaults->fallback('build_critic'),
            ...$this->aiDefaults->candidates('builder'),
        ])));

        return array_slice($chain, 0, self::MAX_ATTEMPTS);
    }

    /**
     * One pass on one model. Null on any failure — missing provider, timeout,
     * unparseable — so the caller advances to the backup.
     *
     * @return array<string, mixed>|null
     */
    protected function attempt(
        string $request,
        string $sheet,
        User $user,
        string $model,
        App $app,
        ?string $conversationId,
    ): ?array {
        $schemaFn = fn (JsonSchema $schema): array => [
            'complete' => $schema->boolean()
                ->description('true ONLY when nothing asked for is missing. Unrequested findings do not make it false.')
                ->required(),
            'missing' => $schema->array()
                ->items($schema->string())
                ->description('Asked for and absent. Name what was asked and what the app has instead. Empty when complete.'),
            'unrequested' => $schema->array()
                ->items($schema->string())
                ->description('Present, never asked for, and not implied by the request. Invented subject matter only — never ordinary CRUD scaffolding.'),
            'summary' => $schema->string()
                ->description('One sentence a person can read: does this app answer the request?'),
        ];

        try {
            $provider = $this->providers->resolveProviderForCatalogModel($model, $user) ?? Lab::Anthropic;

            // The sheet is the stable, reusable half of the prompt, so it is
            // registered as the cacheable context: two passes over one build
            // (primary then retry) re-read it at cache rates.
            $agent = (new ExpressGateAgent(self::INSTRUCTIONS, $schemaFn, $sheet))->forModel($model);

            $response = $agent->prompt(
                "REQUEST (verbatim):\n{$request}",
                provider: $provider,
                model: $model,
                timeout: self::TIMEOUT_SECONDS,
            );

            $this->recordUsage($model, $user, $app, $conversationId, $response->usage ?? null);

            $decoded = $response instanceof Arrayable ? $response->toArray() : null;
            if (! is_array($decoded) || ! array_key_exists('complete', $decoded)) {
                return null;
            }

            return [
                'complete' => (bool) $decoded['complete'],
                'missing' => array_values(array_filter((array) ($decoded['missing'] ?? []), 'is_string')),
                'unrequested' => array_values(array_filter((array) ($decoded['unrequested'] ?? []), 'is_string')),
                'summary' => (string) ($decoded['summary'] ?? ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function recordUsage(string $model, User $user, App $app, ?string $conversationId, mixed $usage): void
    {
        try {
            app(AiUsageRecorder::class)->record(
                'build_critic', $model, $user, $user->organization_id, $usage,
                appId: $app->id, conversationId: $conversationId,
            );
        } catch (\Throwable) {
            // Usage accounting is best-effort, like every other call site.
        }
    }
}
