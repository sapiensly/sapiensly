<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Models\WorkflowRun;
use App\Services\Manifest\AppManifestService;
use App\Services\Manifest\InvalidManifestException;
use App\Services\Workflows\WorkflowEngine;
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
    ) {}

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
