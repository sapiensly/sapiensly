<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationAuthType;
use App\Models\App;
use App\Models\Channel;
use App\Models\Integration;
use App\Models\IntegrationUserToken;
use App\Models\Tool;
use App\Models\WorkflowProposal;
use App\Models\WorkflowRun;
use App\Services\Connectors\ConnectorActionResolver;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use App\Services\Workflows\WorkflowAssertionEvaluator;
use App\Services\Workflows\WorkflowEngine;
use App\Services\Workflows\WorkflowProposalService;
use App\Services\Workflows\WorkflowWebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backend for the visual App Workflow editor (the tab next to Preview /
 * Schema / Manifest in /apps/{slug}/builder).
 *
 * Two endpoints:
 *  - update($app, $wfId): swap in a workflow object the editor just edited
 *    in the canvas. Builds a single JSON Patch op against the live manifest
 *    and routes it through AppManifestService::applyPatch — the same path
 *    Claude uses — so versioning, validation and audit all "just work".
 *  - run($app, $wfId): kick off a manual-trigger workflow and return the
 *    resulting WorkflowRun (with its WorkflowStepRun children) so the
 *    editor can render the execution trace.
 *
 * No business logic lives here. The heavy lifting is split between
 * AppManifestService (apply + validate) and WorkflowEngine (execute).
 */
class AppWorkflowController extends Controller
{
    public function __construct(
        private AppManifestService $manifestService,
        private WorkflowEngine $engine,
        private ConnectorActionResolver $connectorActions,
        private WorkflowAssertionEvaluator $assertions,
        private WorkflowWebhookSignature $webhookSignatures,
        private WorkflowProposalService $proposals,
    ) {}

    /**
     * Pending gated-write proposals for this app — the writes a real run halted
     * on (propose-don't-mutate). The approval gate the runtime UI renders.
     */
    public function pendingProposals(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $proposals = WorkflowProposal::query()
            ->where('app_id', $app->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WorkflowProposal $p): array => [
                'id' => $p->id,
                'workflow_id' => $p->workflow_id,
                'run_id' => $p->run_id,
                'step_id' => $p->step_id,
                'effect' => $p->effect,
                'preview' => $p->preview,
            ]);

        return response()->json(['proposals' => $proposals]);
    }

    public function approveProposal(Request $request, App $app, string $proposalId): JsonResponse
    {
        $this->assertCanAccess($request, $app);
        $proposal = $this->findProposal($app, $proposalId);

        $outcome = $this->proposals->approve($proposal, $request->user());

        return response()->json($outcome, $outcome['ok'] ? 200 : 422);
    }

    public function dismissProposal(Request $request, App $app, string $proposalId): JsonResponse
    {
        $this->assertCanAccess($request, $app);
        $proposal = $this->findProposal($app, $proposalId);

        return response()->json(['ok' => $this->proposals->dismiss($proposal, $request->user())]);
    }

    private function findProposal(App $app, string $proposalId): WorkflowProposal
    {
        $proposal = WorkflowProposal::query()
            ->where('app_id', $app->id)
            ->whereKey($proposalId)
            ->first();

        if ($proposal === null) {
            throw new NotFoundHttpException("Proposal '{$proposalId}' not found.");
        }

        return $proposal;
    }

    /**
     * The signed ingress URL and HMAC secret for a webhook.inbound workflow, so
     * the editor can show the user what to paste into the external provider. The
     * secret is derived (not stored) — stable and safe to reveal to the owner.
     */
    public function webhookInfo(Request $request, App $app, string $workflowId): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        return response()->json([
            'url' => route('webhooks.flows.receive', ['app' => $app->id, 'workflow' => $workflowId]),
            'secret' => $this->webhookSignatures->secretFor($app->id, $workflowId),
            'signature_header' => 'X-Sapiensly-Signature',
        ]);
    }

    /**
     * Feed the visual editor the tenant's integrations and, per integration,
     * the typed connector actions a connector.call step can compose against.
     * Fetched lazily by the canvas (not threaded through the manifest) because
     * integrations change out-of-band — a flow can provision one mid-build.
     */
    public function connectorActions(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $user = $request->user();

        $integrations = Integration::query()
            ->forAccountContext($user)
            ->orderBy('name')
            ->get();

        $authorizedIds = IntegrationUserToken::query()
            ->where('user_id', $user->id)
            ->whereIn('integration_id', $integrations->pluck('id'))
            ->get()
            ->filter(fn (IntegrationUserToken $token): bool => $token->isAuthorized())
            ->pluck('integration_id')
            ->all();

        $actionsByIntegration = Tool::query()
            ->forAccountContext($user)
            ->whereNotNull('config->integration_id')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Tool $tool): string => (string) ($tool->config['integration_id'] ?? ''))
            ->map(fn ($tools) => $tools->map(fn (Tool $tool): array => $this->connectorActions->resolve($tool)->jsonSerialize())->values());

        $payload = $integrations->map(fn (Integration $integration): array => [
            'id' => $integration->id,
            'name' => $integration->name,
            'authorized' => $this->integrationAuthorized($integration, $authorizedIds),
            'actions' => $actionsByIntegration->get($integration->id, collect())->all(),
        ]);

        return response()->json(['integrations' => $payload]);
    }

    /**
     * The org's chat channels (WhatsApp/widget), for the
     * channel.message_received trigger's channel picker.
     */
    public function channels(Request $request, App $app): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $channels = Channel::query()
            ->forAccountContext($request->user())
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'name' => $channel->name,
                'type' => $channel->channel_type->value,
            ]);

        return response()->json(['channels' => $channels]);
    }

    /**
     * @param  list<string>  $authorizedIds
     */
    private function integrationAuthorized(Integration $integration, array $authorizedIds): bool
    {
        $authType = $integration->auth_type;

        if ($authType === IntegrationAuthType::None) {
            return true;
        }

        if ($authType === IntegrationAuthType::OAuth2AuthorizationCode) {
            return in_array($integration->id, $authorizedIds, true);
        }

        return $integration->status !== 'draft';
    }

    /**
     * Replace a single workflow in the manifest with the payload the editor
     * just produced. We resolve the workflow by ID (NOT array index) so we
     * stay safe against concurrent edits that may have shifted positions —
     * same defence as DeleteBlockByIdTool uses for blocks.
     */
    public function update(Request $request, App $app, string $workflowId): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $request->validate([
            'workflow' => ['required', 'array'],
            'workflow.id' => ['required', 'string'],
        ]);

        // Use ->input() instead of the validated bag — validate() only
        // returns the keys we listed, which would drop slug/name/trigger/
        // steps and trigger spurious "required slug missing" errors from
        // ManifestValidator. The ManifestValidator IS the schema authority
        // for the whole workflow payload.
        $workflow = $request->input('workflow');
        if (! is_array($workflow)) {
            throw new HttpException(422, 'workflow must be a JSON object.');
        }

        // The ID in the body MUST match the URL parameter — protects
        // against the editor's state diverging from its URL (e.g. user
        // hot-edits id field while a save is in flight).
        if (($workflow['id'] ?? null) !== $workflowId) {
            throw new HttpException(422, 'workflow.id in body must match the URL parameter.');
        }

        $manifest = $this->manifestService->getActiveManifest($app);
        if (! is_array($manifest)) {
            throw new HttpException(404, 'App has no active manifest yet.');
        }

        $workflows = $manifest['workflows'] ?? [];
        $index = $this->findWorkflowIndex($workflows, $workflowId);
        if ($index === null) {
            // New workflow — append. The editor uses this same endpoint for
            // create + update; the URL ID is the proposed id of the new
            // workflow and the validator will enforce uniqueness against
            // the rest of the manifest.
            $ops = [
                ['op' => 'add', 'path' => '/workflows/-', 'value' => $workflow],
            ];
            $summary = 'Workflow '.($workflow['name'] ?? $workflowId).' creado en el editor visual.';
        } else {
            $ops = [
                ['op' => 'replace', 'path' => '/workflows/'.$index, 'value' => $workflow],
            ];
            $summary = 'Workflow '.($workflow['name'] ?? $workflowId).' editado en el editor visual.';
        }

        // When the manifest has no `workflows` key at all, we need an `add`
        // op that creates the array first (RFC 6902 doesn't auto-create
        // parent containers). Detect and prepend.
        if (! array_key_exists('workflows', $manifest)) {
            array_unshift($ops, ['op' => 'add', 'path' => '/workflows', 'value' => []]);
        }

        try {
            $version = $this->manifestService->applyPatch($app, $ops, $request->user(), $summary);
        } catch (InvalidManifestException $e) {
            // Capture the offending payload so we can diagnose mismatches
            // between what the editor THINKS it sent vs. what reached the
            // validator. Helps when Opis' oneOf error surfacing is
            // misleading (it reports object_id-missing for log steps when
            // really the type-const failed in another branch).
            Log::warning('AppWorkflowController::update validation failed', [
                'app_id' => $app->id,
                'workflow_id' => $workflowId,
                'workflow' => $workflow,
                'errors' => $e->result->errorsArray(),
            ]);

            return response()->json([
                'error' => 'invalid_manifest',
                'message' => 'The workflow did not pass schema validation.',
                'errors' => $e->result->errorsArray(),
            ], 422);
        }

        return response()->json([
            'version_id' => $version->id,
            'version_number' => $version->version_number,
            'manifest' => $version->manifest,
        ]);
    }

    /**
     * Manually trigger a workflow. Only `manual` triggers are accepted —
     * record.* triggers must fire from the runtime via the real event,
     * not from the editor.
     */
    public function run(Request $request, App $app, string $workflowId): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $manifest = $this->manifestService->getActiveManifest($app);
        if (! is_array($manifest)) {
            throw new NotFoundHttpException('App has no active manifest yet.');
        }

        $workflow = collect($manifest['workflows'] ?? [])
            ->first(fn (array $w) => ($w['id'] ?? null) === $workflowId);

        if ($workflow === null) {
            throw new NotFoundHttpException("Workflow '{$workflowId}' not found in the active manifest.");
        }

        $triggerType = $workflow['trigger']['type'] ?? null;
        if ($triggerType !== 'manual') {
            throw new HttpException(422, "Workflow '{$workflowId}' is not a manual trigger — run it from the runtime instead.");
        }

        // Disabled workflows are skipped on real events too — refuse here
        // for symmetry.
        if (($workflow['enabled'] ?? true) === false) {
            throw new HttpException(422, "Workflow '{$workflowId}' is disabled.");
        }

        $run = $this->engine->run($app, $manifest, $workflow, 'manual', [], $request->user());

        return response()->json([
            'run' => [
                'id' => $run->id,
                'workflow_id' => $run->workflow_id,
                'trigger_type' => $run->trigger_type,
                'status' => $run->status,
                'variables' => $run->variables,
                'error' => $run->error,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'steps' => $run->steps()
                    ->orderBy('sequence_index')
                    ->get()
                    ->map(fn ($s) => [
                        'id' => $s->id,
                        'step_id' => $s->step_id,
                        'step_type' => $s->step_type,
                        'status' => $s->status,
                        'sequence_index' => $s->sequence_index,
                        'output' => $s->output,
                        'error' => $s->error,
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * Verify a workflow by dry-run: seed the trigger inputs, execute with every
     * write SIMULATED (never applied), then evaluate declarative assertions and
     * return a legible pass/fail report (FR-2). External writes are shown as
     * Proposal previews; internal record writes never touch real records.
     */
    public function verify(Request $request, App $app, string $workflowId): JsonResponse
    {
        $this->assertCanAccess($request, $app);

        $request->validate([
            'trigger_payload' => ['nullable', 'array'],
            'assertions' => ['nullable', 'array'],
        ]);

        $manifest = $this->manifestService->getActiveManifest($app);
        if (! is_array($manifest)) {
            throw new NotFoundHttpException('App has no active manifest yet.');
        }

        $workflow = collect($manifest['workflows'] ?? [])
            ->first(fn (array $w) => ($w['id'] ?? null) === $workflowId);

        if ($workflow === null) {
            throw new NotFoundHttpException("Workflow '{$workflowId}' not found in the active manifest.");
        }

        $triggerType = $workflow['trigger']['type'] ?? 'manual';
        $payload = (array) $request->input('trigger_payload', []);

        $run = $this->engine->run($app, $manifest, $workflow, $triggerType, $payload, $request->user(), dryRun: true);
        $run->load(['steps' => fn ($q) => $q->orderBy('sequence_index')]);

        $assertions = $request->input('assertions');
        if (! is_array($assertions) || $assertions === []) {
            $assertions = $this->assertions->defaultAssertions($workflow);
        }

        $results = $this->assertions->evaluate($run, $assertions);

        $simulatedWrites = $run->steps
            ->filter(fn ($s) => ($s->output['simulated'] ?? false) === true)
            ->map(fn ($s) => [
                'step_id' => $s->step_id,
                'step_type' => $s->step_type,
                'effect' => $s->output['effect'] ?? 'write',
                'preview' => $s->output['proposal']['preview'] ?? null,
            ])
            ->values()
            ->all();

        return response()->json([
            'passed' => $run->status === 'completed' && collect($results)->every(fn ($r) => $r['passed']),
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'error' => $run->error,
                'steps' => $run->steps->map(fn ($s) => [
                    'step_id' => $s->step_id,
                    'step_type' => $s->step_type,
                    'status' => $s->status,
                    'output' => $s->output,
                    'error' => $s->error,
                ])->all(),
            ],
            'assertions' => $results,
            'simulated_writes' => $simulatedWrites,
        ]);
    }

    private function assertCanAccess(Request $request, App $app): void
    {
        abort_unless($app->isVisibleTo($request->user()), 403);
    }

    /**
     * Returns the array index of the workflow with the given id, or null if
     * absent. Same lookup pattern that DeleteBlockByIdTool uses for blocks
     * — never hand-count indices.
     *
     * @param  list<array<string, mixed>>  $workflows
     */
    private function findWorkflowIndex(array $workflows, string $workflowId): ?int
    {
        foreach ($workflows as $i => $wf) {
            if (is_array($wf) && ($wf['id'] ?? null) === $workflowId) {
                return $i;
            }
        }

        return null;
    }
}
