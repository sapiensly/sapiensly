<?php

use App\Ai\Tools\Builder\CritiqueLandingDesignTool;
use App\Jobs\RunBuilderAiJob;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use App\Services\Landing\LandingDesignCritic;
use App\Services\Manifest\AppManifestService;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The ship:true rail. "Do not finish until ship:true" is a prompt rule, and an
 * undisciplined model walks straight past it — observed live: a landing build
 * ran ONE gate round, applied the must_fix and called itself done, so the page
 * never received a verdict. The rail makes the platform queue the gate turn
 * itself when an applied landing turn leaves the conversation unshipped.
 */
function railSetup(string $kind = 'landing', string $turnStatus = 'applied'): array
{
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id]);
    $app->forceFill(['kind' => $kind])->save();
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active']);
    $finished = BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant',
        'status' => $turnStatus, 'content' => 'Listo.',
    ]);

    return [$conv, $finished];
}

it('queues a platform gate turn when an applied landing turn never shipped', function () {
    Queue::fake();
    [$conv, $finished] = railSetup();

    app(BuilderAiService::class)->continueForLandingGate($finished, null, 2);

    // A synthetic user turn carrying the gate instruction + a streaming placeholder.
    $queuedUser = BuilderMessage::query()
        ->where('conversation_id', $conv->id)->where('role', 'user')->first();
    expect($queuedUser)->not->toBeNull()
        ->and($queuedUser->content)->toContain('critique_landing_design')
        ->and(BuilderMessage::query()->where('conversation_id', $conv->id)->where('status', 'streaming')->count())->toBe(1);

    // The queued job is auto-flagged, carries no plan budget, and burns one
    // gate credit — the chain is bounded even if the director never ships.
    Queue::assertPushed(RunBuilderAiJob::class, fn (RunBuilderAiJob $job) => $job->autoQueued === true
        && $job->autonomousRemaining === 0
        && $job->gateRemaining === 1);
});

it('does not fire once the conversation has shipped', function () {
    Queue::fake();
    [$conv, $finished] = railSetup();
    $conv->update(['landing_shipped_at' => now()]);

    app(BuilderAiService::class)->continueForLandingGate($finished, null, 2);

    Queue::assertNothingPushed();
});

it('never fires on a PUBLISHED landing — publishing is the user\'s blessing', function () {
    // Observed live: a pre-stamp published landing got an uninvited gate turn
    // after a contrast tweak, and its round-cap ship regressed the live page.
    Queue::fake();
    [$conv, $finished] = railSetup();
    $conv->app->forceFill(['published_at' => now(), 'public_slug' => 'rail-published-guard'])->save();

    app(BuilderAiService::class)->continueForLandingGate($finished, null, 2);

    Queue::assertNothingPushed();
});

it('does not fire for non-landing apps, unapplied turns, or an exhausted budget', function () {
    Queue::fake();

    [, $finishedApp] = railSetup(kind: 'app');
    app(BuilderAiService::class)->continueForLandingGate($finishedApp, null, 2);

    [, $finishedNone] = railSetup(turnStatus: 'none');
    app(BuilderAiService::class)->continueForLandingGate($finishedNone, null, 2);

    [, $finishedCapped] = railSetup();
    app(BuilderAiService::class)->continueForLandingGate($finishedCapped, null, 0);

    Queue::assertNothingPushed();
});

it('does not fire while another turn is queued, or after Detener', function () {
    Queue::fake();

    // A sibling streaming placeholder = a chain is already running.
    [$convBusy, $finishedBusy] = railSetup();
    BuilderMessage::create([
        'conversation_id' => $convBusy->id, 'role' => 'assistant',
        'status' => 'streaming', 'content' => '',
    ]);
    app(BuilderAiService::class)->continueForLandingGate($finishedBusy, null, 2);

    // The user pressed Detener — a stopped build stays stopped.
    [$convStopped, $finishedStopped] = railSetup();
    app(BuilderCancellation::class)->request($convStopped);
    app(BuilderAiService::class)->continueForLandingGate($finishedStopped, null, 2);

    Queue::assertNothingPushed();
});

it('the critique tool stamps landing_shipped_at on the first shipped verdict', function () {
    $user = User::factory()->create();
    // The factory's faker slug is hyphenated; the manifest slug pattern is
    // snake_case, so createVersion below needs a valid one.
    $app = App::factory()->create(['user_id' => $user->id, 'slug' => 'landing_rail_stamp']);
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active']);

    $manifests = app(AppManifestService::class);
    $manifest = $manifests->initialManifest($app);
    $manifests->createVersion($app, $manifest, $user, 'seed');

    $shippingCritic = new class(Mockery::mock(AiDefaults::class), Mockery::mock(AiProviderService::class)) extends LandingDesignCritic
    {
        public function critique(string $intent, string $html, string $css, ?User $user = null, ?string $modelOverride = null, int $round = 1, ?StoredImage $screenshot = null, bool $screenshotIsCurrentDraft = false, array $declaredFonts = [], string $mode = self::MODE_DESIGN): array
        {
            return [
                'ship' => true, 'score' => 92, 'must_fix' => [], 'tells' => [],
                'direction' => [], 'strengths' => [], 'judged_by' => 'design-director',
                'director' => 'ok', 'converged' => true, 'round' => $round,
            ];
        }
    };

    $tool = new CritiqueLandingDesignTool(
        $app->refresh(), $manifests, $shippingCritic,
        user: null, proposeTool: null, conversationId: $conv->id,
    );
    $tool->handle(new ToolRequest(['intent' => 'landing de prueba', 'round' => 1]));

    expect($conv->refresh()->landing_shipped_at)->not->toBeNull();
});
