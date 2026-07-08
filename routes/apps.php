<?php

use App\Http\Controllers\AppAccessController;
use App\Http\Controllers\AppActionController;
use App\Http\Controllers\AppBuilderController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppFileController;
use App\Http\Controllers\AppRuntimeAgentController;
use App\Http\Controllers\AppRuntimeController;
use App\Http\Controllers\AppWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    // No `create` page — the "New App" button POSTs to `store`, which creates an
    // empty app and redirects straight into the Builder (the first prompt names it).
    Route::resource('apps', AppController::class)->except(['edit', 'create']);
    // Back-out of a brand-new app that was never touched (still unnamed, no
    // build) removes it, so leaving the Builder immediately doesn't litter the
    // grid with empty apps. No-op (redirect only) once the app has any content.
    Route::delete('/apps/{app}/discard-empty', [AppController::class, 'discardEmpty'])->name('apps.discard-empty');

    // Builder AI surface — chat that edits the manifest via JSON Patches.
    Route::get('/apps/{app}/builder', [AppBuilderController::class, 'show'])->name('apps.builder');
    Route::post('/apps/{app}/builder/conversations', [AppBuilderController::class, 'startNewConversation'])->name('apps.builder.conversations.new');
    // Builder AI surfaces — each enqueues a paid Claude job, so they share the
    // `builder-ai` throttle (per-user + per-org/min + per-org/day cost ceiling).
    // The 429 fires at HTTP admission, so a throttled request never enqueues a job.
    Route::post('/apps/{app}/builder/messages', [AppBuilderController::class, 'sendMessage'])->middleware('throttle:builder-ai')->name('apps.builder.messages');
    Route::post('/apps/{app}/builder/stop', [AppBuilderController::class, 'stopBuild'])->name('apps.builder.stop');
    Route::post('/apps/{app}/builder/express', [AppBuilderController::class, 'expressDashboard'])->middleware('throttle:builder-ai')->name('apps.builder.express');
    Route::post('/apps/{app}/builder/visual-review', [AppBuilderController::class, 'visualReview'])->middleware('throttle:builder-ai')->name('apps.builder.visual-review');
    Route::post('/apps/{app}/builder/wireframe-import', [AppBuilderController::class, 'wireframeImport'])->middleware('throttle:builder-ai')->name('apps.builder.wireframe-import');
    Route::post('/apps/{app}/builder/design', [AppBuilderController::class, 'updateDesign'])->name('apps.builder.design');
    Route::post('/apps/{app}/builder/messages/{message}/approve', [AppBuilderController::class, 'approve'])->name('apps.builder.approve');
    Route::post('/apps/{app}/builder/messages/{message}/reject', [AppBuilderController::class, 'reject'])->name('apps.builder.reject');
    Route::post('/apps/{app}/builder/messages/{message}/revert', [AppBuilderController::class, 'revert'])->name('apps.builder.revert');

    // Visual workflow editor — replaces a single workflow inside the
    // manifest with the canvas payload, or runs a manual-trigger workflow
    // on demand.
    Route::put('/apps/{app}/builder/workflows/{workflow}', [AppWorkflowController::class, 'update'])
        ->where('workflow', 'wkf_[a-z0-9_]+')
        ->name('apps.builder.workflows.update');
    Route::post('/apps/{app}/builder/workflows/{workflow}/run', [AppWorkflowController::class, 'run'])
        ->where('workflow', 'wkf_[a-z0-9_]+')
        ->middleware('throttle:builder-workflow-run')
        ->name('apps.builder.workflows.run');
    Route::post('/apps/{app}/builder/workflows/{workflow}/verify', [AppWorkflowController::class, 'verify'])
        ->where('workflow', 'wkf_[a-z0-9_]+')
        ->middleware('throttle:builder-workflow-run')
        ->name('apps.builder.workflows.verify');
    Route::get('/apps/{app}/builder/workflows/{workflow}/webhook-info', [AppWorkflowController::class, 'webhookInfo'])
        ->where('workflow', 'wkf_[a-z0-9_]+')
        ->name('apps.builder.workflows.webhook-info');
    Route::get('/apps/{app}/builder/connector-actions', [AppWorkflowController::class, 'connectorActions'])
        ->name('apps.builder.connector-actions');
    Route::get('/apps/{app}/builder/channels', [AppWorkflowController::class, 'channels'])
        ->name('apps.builder.channels');
    // Gated-write proposals (propose-don't-mutate approval gate).
    Route::get('/apps/{app}/builder/workflow-proposals', [AppWorkflowController::class, 'pendingProposals'])
        ->name('apps.builder.workflow-proposals.index');
    Route::post('/apps/{app}/builder/workflow-proposals/{proposal}/approve', [AppWorkflowController::class, 'approveProposal'])
        ->where('proposal', 'whp_[a-z0-9_]+')
        ->name('apps.builder.workflow-proposals.approve');
    Route::post('/apps/{app}/builder/workflow-proposals/{proposal}/dismiss', [AppWorkflowController::class, 'dismissProposal'])
        ->where('proposal', 'whp_[a-z0-9_]+')
        ->name('apps.builder.workflow-proposals.dismiss');
    Route::get('/apps/{app}/builder/objects/{objectId}/records', [AppBuilderController::class, 'objectRecords'])
        ->name('apps.builder.object-records');
    Route::get('/apps/{app}/builder/objects/{objectId}/aggregate', [AppBuilderController::class, 'objectAggregate'])
        ->name('apps.builder.object-aggregate');

    // Access management (Phase 4): who can use the app and in which role. Gated
    // on the app/org-admin set inside the controller, not just app visibility.
    Route::get('/apps/{app}/access', [AppAccessController::class, 'index'])->name('apps.access.index');
    Route::post('/apps/{app}/access', [AppAccessController::class, 'store'])->name('apps.access.store');
    Route::post('/apps/{app}/access/mode', [AppAccessController::class, 'updateMode'])->name('apps.access.mode');
    Route::delete('/apps/{app}/access/{assignment}', [AppAccessController::class, 'destroy'])
        ->where('assignment', 'aur_[a-z0-9_]+')
        ->name('apps.access.destroy');

    // Serve image attachments uploaded with builder chat messages. Auth + the
    // controller re-checks that the requesting user owns the conversation.
    Route::get('/apps/builder/messages/{message}/attachment', [AppBuilderController::class, 'messageAttachment'])
        ->where('message', 'bmsg_[a-z0-9]+')
        ->name('apps.builder.message.attachment');

    // Runtime: /r/{app_slug}/{page_slug?} — what end-users of the App see.
    // Lives under /r to keep it cleanly separated from the admin /apps URLs.
    Route::get('/r/{app_slug}/{page_slug?}', AppRuntimeController::class)
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('page_slug', '[a-z][a-z0-9_]*')
        ->name('apps.runtime');

    Route::post('/r/{app_slug}/actions', AppActionController::class)
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->middleware('throttle:runtime-actions')
        ->name('apps.runtime.actions');

    // Runtime agent (power #3): end-users converse with the app's embedded
    // agent, which reads the app's data through the auto-derived toolset and
    // streams its reply over Reverb. Each message enqueues a paid Claude job,
    // so it shares the runtime-actions throttle.
    Route::post('/r/{app_slug}/agent/conversations', [AppRuntimeAgentController::class, 'startConversation'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->name('apps.runtime.agent.conversations');

    Route::post('/r/{app_slug}/agent/messages', [AppRuntimeAgentController::class, 'sendMessage'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->middleware('throttle:runtime-actions')
        ->name('apps.runtime.agent.messages');

    // The propose-don't-mutate gate (power #3): approve runs the proposed
    // actions through the runtime write path; dismiss discards them.
    Route::post('/r/{app_slug}/agent/messages/{message}/approve', [AppRuntimeAgentController::class, 'approveAction'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('message', 'rmsg_[a-z0-9]+')
        ->middleware('throttle:runtime-actions')
        ->name('apps.runtime.agent.approve');

    Route::post('/r/{app_slug}/agent/messages/{message}/dismiss', [AppRuntimeAgentController::class, 'dismissAction'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('message', 'rmsg_[a-z0-9]+')
        ->name('apps.runtime.agent.dismiss');

    // File upload + serve for file fields in BlockForm. Uploads go via POST
    // and return a {file_id, url, ...} JSON; the GET endpoint streams the
    // bytes back after re-checking that the user can still see the App.
    Route::post('/r/{app_slug}/uploads', [AppFileController::class, 'upload'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->name('apps.runtime.uploads');

    Route::get('/r/{app_slug}/files/{file_id}', [AppFileController::class, 'show'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('file_id', 'fil_[a-z0-9]+')
        ->name('apps.runtime.files');
});
