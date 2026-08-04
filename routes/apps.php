<?php

use App\Http\Controllers\AppAccessController;
use App\Http\Controllers\AppActionController;
use App\Http\Controllers\AppBuilderController;
use App\Http\Controllers\AppBulkActionController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppDocsController;
use App\Http\Controllers\AppEnvironmentController;
use App\Http\Controllers\AppExportController;
use App\Http\Controllers\AppFileController;
use App\Http\Controllers\AppNotificationController;
use App\Http\Controllers\AppPrintController;
use App\Http\Controllers\AppRecordExtractController;
use App\Http\Controllers\AppRecordLookupController;
use App\Http\Controllers\AppRecordOptionsController;
use App\Http\Controllers\AppRecordTrailController;
use App\Http\Controllers\AppRuntimeAgentController;
use App\Http\Controllers\AppRuntimeController;
use App\Http\Controllers\AppVersionsController;
use App\Http\Controllers\AppWorkflowController;
use App\Http\Middleware\BindAppEnvironment;
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

    // Portability: an app as a file. Import and duplicate both go through
    // AppPackage, so a copy is built by exactly the path an installed package
    // takes and cannot drift from it.
    Route::get('/apps/{app}/export', [AppController::class, 'export'])->name('apps.export');
    Route::post('/apps/{app}/duplicate', [AppController::class, 'duplicate'])->name('apps.duplicate');
    Route::post('/apps/import', [AppController::class, 'import'])
        ->middleware('throttle:20,1')
        ->name('apps.import');
    Route::post('/apps/from-template', [AppController::class, 'createFromTemplate'])->name('apps.from-template');
    Route::post('/apps/{app}/save-as-template', [AppController::class, 'saveAsTemplate'])->name('apps.save-as-template');
    Route::delete('/apps/templates/{template}', [AppController::class, 'destroyTemplate'])
        ->where('template', 'tpl_[a-z0-9]+')
        ->name('apps.templates.destroy');

    // The app's history, and the way back. Rolling back has always been
    // possible over MCP and unreachable from the builder — a history nobody
    // can reach is a backup nobody has.
    Route::get('/apps/{app}/versions', [AppVersionsController::class, 'index'])->name('apps.versions');
    Route::get('/apps/{app}/activity', [AppVersionsController::class, 'activity'])->name('apps.activity');
    Route::post('/apps/{app}/versions/{version}/restore', [AppVersionsController::class, 'restore'])
        ->where('version', 'apv_[a-z0-9]+')
        ->name('apps.versions.restore');

    // What the app is and how it works, derived from its manifest on read.
    // Outside the /builder prefix on purpose: they are about the APP, opened
    // from the builder and readable by anyone who can see it.
    Route::get('/apps/{app}/docs', [AppDocsController::class, 'show'])->name('apps.docs');
    Route::get('/apps/{app}/docs/{kind}.md', [AppDocsController::class, 'download'])
        ->where('kind', 'manual|technical')
        ->name('apps.docs.download');

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
    Route::post('/apps/{app}/builder/preview-shot', [AppBuilderController::class, 'previewShot'])->middleware('throttle:60,1')->name('apps.builder.preview-shot');
    // Mid-turn draft screenshots for the design director (Stage 2 eyes): the
    // UI claims the draft payload it was asked to render, then posts the shot.
    Route::get('/apps/{app}/builder/draft-shot/{nonce}', [AppBuilderController::class, 'draftShotClaim'])->middleware('throttle:60,1')->name('apps.builder.draft-shot.claim');
    Route::post('/apps/{app}/builder/draft-shot/{nonce}', [AppBuilderController::class, 'draftShotStore'])->middleware('throttle:60,1')->name('apps.builder.draft-shot.store');
    // Discarding a plan proposal is deterministic: it skips the targeted
    // build-plan steps so the autonomous loop cannot build rejected work.
    Route::post('/apps/{app}/builder/messages/{message}/discard-plan', [AppBuilderController::class, 'discardPlanProposal'])->name('apps.builder.messages.discard-plan');
    Route::post('/apps/{app}/builder/publish-landing', [AppBuilderController::class, 'publishLanding'])->name('apps.builder.publish-landing');
    Route::post('/apps/{app}/builder/unpublish-landing', [AppBuilderController::class, 'unpublishLanding'])->name('apps.builder.unpublish-landing');
    // Spreadsheet import: analyze returns the plan (writes nothing), run does
    // the work. Both take the file; neither trusts a client-supplied plan.
    Route::post('/apps/{app}/builder/import/analyze', [AppBuilderController::class, 'importAnalyze'])
        ->middleware('throttle:30,1')
        ->name('apps.builder.import.analyze');
    Route::get('/apps/{app}/builder/import/{importId}', [AppBuilderController::class, 'importStatus'])
        ->where('importId', 'imp_[a-z0-9]+')
        ->name('apps.builder.import.status');
    Route::post('/apps/{app}/builder/import/run', [AppBuilderController::class, 'importRun'])
        ->middleware('throttle:20,1')
        ->name('apps.builder.import.run');

    // API keys for the app's REST data API. Minting returns the token once;
    // it is stored only as a hash, so it can never be shown again.
    Route::get('/apps/{app}/builder/api-keys', [AppBuilderController::class, 'apiKeys'])->name('apps.builder.api-keys');
    Route::post('/apps/{app}/builder/api-keys', [AppBuilderController::class, 'createApiKey'])->name('apps.builder.api-keys.create');
    Route::delete('/apps/{app}/builder/api-keys/{key}', [AppBuilderController::class, 'revokeApiKey'])->name('apps.builder.api-keys.revoke');

    // Who may sign in to the portal. Invite mode is unusable without this.
    Route::get('/apps/{app}/builder/portal-users', [AppBuilderController::class, 'portalUsers'])->name('apps.builder.portal-users');
    Route::post('/apps/{app}/builder/portal-users', [AppBuilderController::class, 'managePortalUser'])->name('apps.builder.portal-users.manage');

    Route::post('/apps/{app}/builder/publish-portal', [AppBuilderController::class, 'publishPortal'])->name('apps.builder.publish-portal');
    Route::post('/apps/{app}/builder/unpublish-portal', [AppBuilderController::class, 'unpublishPortal'])->name('apps.builder.unpublish-portal');
    Route::post('/apps/{app}/builder/landing-domain/connect', [AppBuilderController::class, 'landingDomainConnect'])->name('apps.builder.landing-domain.connect');
    Route::post('/apps/{app}/builder/landing-domain/verify', [AppBuilderController::class, 'landingDomainVerify'])->middleware('throttle:30,1')->name('apps.builder.landing-domain.verify');
    Route::post('/apps/{app}/builder/landing-domain/disconnect', [AppBuilderController::class, 'landingDomainDisconnect'])->name('apps.builder.landing-domain.disconnect');
    Route::post('/apps/{app}/builder/blocks/update', [AppBuilderController::class, 'updateBlock'])->name('apps.builder.blocks.update');
    Route::post('/apps/{app}/builder/charts', [AppBuilderController::class, 'addChart'])->name('apps.builder.charts.add');
    Route::get('/apps/{app}/builder/recommendations', [AppBuilderController::class, 'recommendations'])->name('apps.builder.recommendations');
    Route::post('/apps/{app}/builder/charts/from-recommendation', [AppBuilderController::class, 'addRecommendation'])->name('apps.builder.charts.recommend');
    Route::post('/apps/{app}/builder/blocks/move', [AppBuilderController::class, 'moveBlock'])->name('apps.builder.blocks.move');
    Route::post('/apps/{app}/builder/blocks/delete', [AppBuilderController::class, 'deleteBlock'])->name('apps.builder.blocks.delete');
    Route::post('/apps/{app}/builder/blocks/duplicate', [AppBuilderController::class, 'duplicateBlock'])->name('apps.builder.blocks.duplicate');
    Route::post('/apps/{app}/builder/blocks/content', [AppBuilderController::class, 'setBlockContent'])->name('apps.builder.blocks.content');
    Route::post('/apps/{app}/builder/blocks/style', [AppBuilderController::class, 'styleElement'])->name('apps.builder.blocks.style');
    Route::post('/apps/{app}/builder/blocks/style/reset', [AppBuilderController::class, 'resetElement'])->name('apps.builder.blocks.style.reset');
    // Landing link inventory + bulk retarget: "where do my buttons go?" is a
    // question about the whole page, so it is answered (and edited) page-wide.
    Route::get('/apps/{app}/builder/links', [AppBuilderController::class, 'landingLinks'])->name('apps.builder.links');
    Route::post('/apps/{app}/builder/links/retarget', [AppBuilderController::class, 'retargetLinks'])->name('apps.builder.links.retarget');
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
        ->middleware(BindAppEnvironment::class)
        ->name('apps.runtime');

    // The environment binds here too, or a form submitted from the sandbox
    // would write its record into production.
    Route::post('/r/{app_slug}/actions', AppActionController::class)
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->middleware(['throttle:runtime-actions', BindAppEnvironment::class])
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

    // A printable copy of a page: the record the reader is looking at, as a
    // PDF they can hand to the customer who does not have the app. Everything
    // on the query string rides along, so ?id=… prints THAT record.
    Route::get('/r/{app_slug}/{page_slug}/pdf', [AppPrintController::class, 'download'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('page_slug', '[a-z][a-z0-9_]*')
        ->name('apps.runtime.pdf');

    // In-app notification inbox: what notify.send raised for THIS person in
    // THIS app. Filtered by recipient inside the controller — RLS scopes the
    // tenant, the recipient filter scopes the person.
    Route::get('/r/{app_slug}/notifications', [AppNotificationController::class, 'index'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->name('apps.runtime.notifications');

    Route::post('/r/{app_slug}/notifications/read', [AppNotificationController::class, 'markRead'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->name('apps.runtime.notifications.read');

    // Download an object's records. Same access context as the page, so an
    // The same edit applied to the rows somebody picked. Bound to the
    // environment like every other write, or a bulk delete fired from the
    // sandbox would take real records with it.
    Route::post('/r/{app_slug}/bulk', AppBulkActionController::class)
        ->middleware(['throttle:30,1', BindAppEnvironment::class])
        ->name('apps.runtime.bulk');

    // Emptying the sandbox. Refused unless the session says you are in it.
    Route::post('/r/{app_slug}/environment/reset', [AppEnvironmentController::class, 'reset'])
        ->middleware(['throttle:10,1', BindAppEnvironment::class])
        ->name('apps.runtime.environment.reset');
    Route::post('/r/{app_slug}/environment/seed', [AppEnvironmentController::class, 'seed'])
        ->middleware(['throttle:10,1', BindAppEnvironment::class])
        ->name('apps.runtime.environment.seed');

    // A record's history — what changed, and what people said about it.
    Route::get('/r/{app_slug}/records/{record_id}/trail', [AppRecordTrailController::class, 'index'])
        ->where('record_id', 'rec_[a-z0-9]+')
        ->middleware(['throttle:120,1', BindAppEnvironment::class])
        ->name('apps.runtime.trail');
    Route::post('/r/{app_slug}/records/{record_id}/trail', [AppRecordTrailController::class, 'store'])
        ->where('record_id', 'rec_[a-z0-9]+')
        ->middleware(['throttle:30,1', BindAppEnvironment::class])
        ->name('apps.runtime.trail.store');

    // The records a relation field can point at. Same access gate as the table
    // that shows them; authenticated runtime only (see the controller on why a
    // public portal does not get an enumeration endpoint).
    Route::get('/r/{app_slug}/fields/{field_id}/options', AppRecordOptionsController::class)
        ->where('field_id', 'fld_[a-z0-9]+')
        ->middleware(['throttle:120,1', BindAppEnvironment::class])
        ->name('apps.runtime.options');

    // Read a document, fill a form. Writes nothing — see the controller on why
    // an extraction is a suggestion and never a saved record.
    Route::post('/r/{app_slug}/objects/{object_slug}/extract', AppRecordExtractController::class)
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('object_slug', '[a-z][a-z0-9_]*')
        ->middleware(['throttle:20,1', BindAppEnvironment::class])
        ->name('apps.runtime.extract');

    // Which record carries a scanned code. Addressed by the field for the same
    // reason as the options above, and answering with at most one id — it is a
    // lookup, not a search.
    Route::get('/r/{app_slug}/fields/{field_id}/lookup', AppRecordLookupController::class)
        ->where('field_id', 'fld_[a-z0-9]+')
        ->middleware(['throttle:120,1', BindAppEnvironment::class])
        ->name('apps.runtime.lookup');

    // export can never return more than the table showed.
    Route::get('/r/{app_slug}/objects/{object_slug}/export', [AppExportController::class, '__invoke'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('object_slug', '[a-z][a-z0-9_]*')
        ->middleware(BindAppEnvironment::class)->middleware('throttle:20,1')
        ->name('apps.runtime.export');

    // Prepared exports: for volumes where the request timeout, not memory, is
    // what would fail. Start one, poll it, collect the file.
    Route::post('/r/{app_slug}/objects/{object_slug}/export/queue', [AppExportController::class, 'queue'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('object_slug', '[a-z][a-z0-9_]*')
        ->middleware('throttle:10,1')
        ->name('apps.runtime.export.queue');

    Route::get('/r/{app_slug}/objects/{object_slug}/export/{exportId}', [AppExportController::class, 'status'])
        ->where(['app_slug' => '[a-z][a-z0-9_]*', 'object_slug' => '[a-z][a-z0-9_]*', 'exportId' => 'exp_[a-z0-9]+'])
        ->name('apps.runtime.export.status');

    Route::get('/r/{app_slug}/objects/{object_slug}/export/{exportId}/download', [AppExportController::class, 'download'])
        ->where(['app_slug' => '[a-z][a-z0-9_]*', 'object_slug' => '[a-z][a-z0-9_]*', 'exportId' => 'exp_[a-z0-9]+'])
        ->name('apps.runtime.export.download');

    Route::get('/r/{app_slug}/files/{file_id}', [AppFileController::class, 'show'])
        ->where('app_slug', '[a-z][a-z0-9_]*')
        ->where('file_id', 'fil_[a-z0-9]+')
        ->name('apps.runtime.files');
});
