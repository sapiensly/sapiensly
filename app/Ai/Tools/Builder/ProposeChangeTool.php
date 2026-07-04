<?php

namespace App\Ai\Tools\Builder;

use App\Models\App;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\ManifestIdFiller;
use App\Services\Manifest\ManifestPatch;
use App\Services\Manifest\ManifestSchemaCatalog;
use App\Services\Manifest\ManifestValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Capture-only tool. The model calls propose_change with an RFC 6902 patch and
 * a human-readable summary. We validate the resulting manifest, store the
 * proposal on the tool instance, and return a confirmation message — we do NOT
 * apply the patch here. The user approves explicitly from the UI.
 */
class ProposeChangeTool implements Tool
{
    /**
     * The accumulated proposal for the current turn, ready to apply.
     *
     * Each successful call to recordProposal() appends its ops here and
     * advances $runningDraft. That makes multiple propose_change calls in one
     * turn behave additively — exactly how the model thinks about edits —
     * rather than the older "last call wins" semantics that silently lost
     * earlier patches like "add field X" when a follow-up "update form Y"
     * landed.
     *
     * @var array{patch: list<array<string, mixed>>, summary: string, draft_manifest: array<string, mixed>}|null
     */
    private ?array $lastProposal = null;

    /** @var list<string> */
    private array $accumulatedSummaries = [];

    /** Running draft after every successful propose_change in this turn. */
    private ?array $runningDraft = null;

    /**
     * Invoked after every SUCCESSFUL proposal with the accumulated
     * {patch, summary, draft_manifest}. The orchestrator uses it to checkpoint
     * valid progress mid-turn, so a hard worker timeout doesn't discard it.
     *
     * @var (callable(array{patch: list<array<string, mixed>>, summary: string, draft_manifest: array<string, mixed>}): void)|null
     */
    private $onProgress = null;

    /**
     * Register a checkpoint callback fired after each successful propose_change.
     *
     * @param  callable(array{patch: list<array<string, mixed>>, summary: string, draft_manifest: array<string, mixed>}): void  $callback
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback;
    }

    public function __construct(
        private App $appModel,
        private AppManifestService $manifestService,
        private ManifestValidator $validator,
    ) {}

    public function name(): string
    {
        return 'propose_change';
    }

    public function description(): string
    {
        return <<<'DESC'
Propose changes to the manifest as an RFC 6902 JSON Patch. The patch is validated
against the latest draft of THIS turn (so multiple calls stack), then applied
automatically when the turn ends. The user can undo from the chat.

Calls in the same turn ACCUMULATE: ops from call #1 are kept, and call #2's ops
are validated as if call #1 had already been applied. This means you can split a
big change across logical steps — e.g. first add a field, then reference it from
a form — without one cancelling the other. The final audit summary joins the
change_summary strings of every successful call with " · ".

`ops` must be an array of patch operations, each one of:
  - {"op":"add","path":"/objects/-","value":{...}}
  - {"op":"replace","path":"/name","value":"New Name"}
  - {"op":"remove","path":"/pages/0"}
  - {"op":"move"|"copy","from":"/x","path":"/y"}
  - {"op":"test","path":"/version","value":1}

For arrays, `/-` APPENDS to the end and a numeric index INSERTS before that
position (both keep the inserted value intact): `add /pages/0/blocks/0` puts a
block first, `add /pages/0/blocks/2` puts it before the current third block.
Use `move` to reorder, e.g. `{"op":"move","from":"/pages/0/blocks/3","path":"/pages/0/blocks/0"}`.
There is no need to fall back to append-only — pick the index you want.

`change_summary` is one short sentence explaining the change in plain language.

Returns either `{ok: true, ...}` with the running op count, or
`{ok: false, errors: [...]}` if THIS call's ops produced an invalid draft. On
error the running draft is unchanged — fix the ops and call again.
DESC;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ops' => $schema
                ->array()
                ->description('RFC 6902 ops array. Each item is {op, path, value?, from?}.')
                ->required(),
            'change_summary' => $schema
                ->string()
                ->description('One short sentence describing the change.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->all();
        $ops = $args['ops'] ?? [];
        $summary = $args['change_summary'] ?? '';

        if (! is_array($ops) || $ops === []) {
            return json_encode([
                'ok' => false,
                'errors' => [['path' => '/ops', 'message' => 'ops must be a non-empty array', 'code' => 'bad_input']],
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode($this->recordProposal($ops, (string) $summary), JSON_THROW_ON_ERROR);
    }

    /**
     * Validate `$ops` against the current manifest and stash them as the last
     * proposal of the turn. Public so sibling tools (e.g. DeleteBlockByIdTool)
     * can submit proposals through the same gate without duplicating the
     * validation/draft pipeline.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, mixed>
     */
    public function recordProposal(array $ops, string $summary): array
    {
        // Let the model omit the `id` on nodes it adds — fill the ones the schema
        // requires (objects/fields/blocks/options/…) server-side, so patches are
        // smaller and never fail on a missing or mis-shaped id.
        $ops = ManifestIdFiller::fill(array_values($ops));

        // Each successful proposal in a turn stacks on top of the previous one.
        // We validate THIS call's ops against the running draft (or the live
        // manifest, if this is the first call), so the model can build up an
        // edit incrementally — e.g. add a field, then reference it from a
        // form — without losing the earlier ops on the next call.
        $base = $this->currentManifest();
        if ($base === null) {
            return [
                'ok' => false,
                'errors' => [['path' => '/', 'message' => 'No active manifest exists for this app yet.', 'code' => 'no_manifest']],
            ];
        }

        try {
            $draft = $this->applyPatch($base, $ops);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'errors' => [['path' => '/ops', 'message' => 'Patch could not be applied: '.$e->getMessage(), 'code' => 'patch_apply_failed']],
            ];
        }

        $validation = $this->validator->validate($draft);
        if (! $validation->valid) {
            return [
                'ok' => false,
                'errors' => $this->withSchemaHints($validation->errorsArray(), $draft),
            ];
        }

        $this->runningDraft = $draft;
        if (trim($summary) !== '') {
            $this->accumulatedSummaries[] = trim($summary);
        }

        $previousPatch = $this->lastProposal['patch'] ?? [];
        $combinedPatch = array_merge($previousPatch, array_values($ops));

        $this->lastProposal = [
            'patch' => $combinedPatch,
            'summary' => $this->joinedSummary(),
            'draft_manifest' => $draft,
        ];

        // Checkpoint the accumulated valid work so a mid-turn timeout/crash
        // (the loop runs in a queue worker with a hard wall-clock limit) leaves
        // something to recover from instead of discarding the whole turn.
        if ($this->onProgress !== null) {
            ($this->onProgress)($this->lastProposal);
        }

        $response = [
            'ok' => true,
            'summary' => $summary,
            'op_count' => count($ops),
            'total_op_count' => count($combinedPatch),
            'message' => 'Proposal recorded. Calling propose_change again in this turn will stack additional ops onto it.',
        ];

        // Non-blocking completeness advisories. The draft is valid and applied,
        // but something is wired to do nothing (e.g. a submit that never saves).
        // The model MUST resolve these before claiming the task is done — fix
        // them, or tell the user plainly what could not be completed and why.
        $warnings = $validation->warningsArray();
        if ($warnings !== []) {
            $response['warnings'] = $warnings;
            $response['message'] .= ' WARNING: some controls have no effect (see `warnings`). Do NOT report success until each is fixed or you have told the user exactly what you could not complete.';
        }

        return $response;
    }

    /**
     * Attach the exact parameter contract of the typed node nearest to each
     * failing path (up to 3), so the model fixes the SHAPE in one round instead
     * of guessing — the validation error says what's wrong; the hint shows what
     * right looks like (required/optional props + allowed enum values).
     *
     * @param  list<array<string, mixed>>  $errors
     * @param  array<string, mixed>  $draft
     * @return list<array<string, mixed>>
     */
    private function withSchemaHints(array $errors, array $draft): array
    {
        $catalog = new ManifestSchemaCatalog($this->validator);
        $hinted = 0;

        foreach ($errors as $i => $error) {
            if ($hinted >= 3) {
                break;
            }
            $type = $this->nearestTypedNode($draft, (string) ($error['path'] ?? ''));
            if ($type === null) {
                continue;
            }
            foreach (['block', 'field', 'action', 'step', 'trigger'] as $category) {
                $params = $catalog->params($category, $type);
                if ($params !== null) {
                    $errors[$i]['hint'] = ['category' => $category, 'type' => $type, 'expected_params' => $params];
                    $hinted++;
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Walk a JSON-pointer path into the draft and return the `type` of the
     * deepest typed node passed through (a block/field/action/step) — the node
     * whose schema the failing property most likely belongs to.
     *
     * @param  array<string, mixed>  $draft
     */
    private function nearestTypedNode(array $draft, string $path): ?string
    {
        $node = $draft;
        $lastType = null;
        foreach (array_filter(explode('/', $path), fn (string $s): bool => $s !== '') as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                break;
            }
            $node = $node[$segment];
            if (is_array($node) && isset($node['type']) && is_string($node['type'])) {
                $lastType = $node['type'];
            }
        }

        return $lastType;
    }

    /**
     * Build a single human-readable summary out of the accumulated summaries
     * fed across multiple propose_change calls in the same turn. Used as the
     * audit log message for the resulting AppVersion.
     */
    private function joinedSummary(): string
    {
        $unique = array_values(array_unique(array_filter($this->accumulatedSummaries, fn ($s) => $s !== '')));
        if ($unique === []) {
            return 'Builder AI change';
        }
        if (count($unique) === 1) {
            return $unique[0];
        }

        return implode(' · ', $unique);
    }

    /**
     * @return array{patch: list<array<string, mixed>>, summary: string, draft_manifest: array<string, mixed>}|null
     */
    public function lastProposal(): ?array
    {
        return $this->lastProposal;
    }

    /**
     * The accumulated draft after every successful propose_change in THIS
     * turn, or null when no proposals have been recorded yet. Exposed so
     * sibling tools (read_manifest, simulate_query) can ground their
     * answer on the in-progress state instead of the persisted manifest
     * — otherwise Claude reads its own "no, you didn't add it" lie back
     * from a stale snapshot and ends up proposing duplicates.
     *
     * @return array<string, mixed>|null
     */
    public function runningDraft(): ?array
    {
        return $this->runningDraft;
    }

    /**
     * The "current" manifest as the model sees it: the in-progress draft
     * when one exists, otherwise the persisted active manifest. This is
     * what every read-side sibling tool (seed_records, simulate_query,
     * delete_block_by_id, etc.) should consult to stay in sync with
     * propose_change ops made earlier in the same turn. Returns null only
     * when no active manifest exists yet AND nothing has been proposed.
     *
     * @return array<string, mixed>|null
     */
    public function currentManifest(): ?array
    {
        return $this->runningDraft ?? $this->manifestService->getActiveManifest($this->appModel);
    }

    /**
     * Number of `propose_change` calls in this turn that have stacked ops
     * onto the running draft. Useful for ReadManifestTool to tell Claude
     * how many ops are pending.
     */
    public function opCount(): int
    {
        return $this->lastProposal === null ? 0 : count($this->lastProposal['patch']);
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $ops
     * @return array<string, mixed>
     */
    private function applyPatch(array $document, array $ops): array
    {
        return ManifestPatch::apply($document, $ops);
    }
}
