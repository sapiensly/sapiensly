<?php

use App\Ai\Tools\Builder\CritiqueBuildTool;
use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Apps\BuildCritic;
use App\Services\Apps\Docs\AppDocs;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as ToolRequest;

/**
 * The closing-review rail. "Call critique_build before you report done" is a
 * prompt rule, and the two builds this was written from both walked past it —
 * each closing with a summary of work it had skipped. The rail makes the
 * platform queue the review turn itself when an applied turn leaves the
 * conversation unreviewed.
 */
function reviewRailSetup(string $kind = 'app', string $turnStatus = 'applied'): array
{
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id]);
    $app->forceFill(['kind' => $kind])->save();
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active']);
    $finished = BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant',
        'status' => $turnStatus, 'content' => 'Listo.',
    ]);

    return [$conv, $finished, $app, $user];
}

/** A critic whose verdict is fixed, so the rail is tested and not the model. */
function railCritic(?array $verdict): BuildCritic
{
    return new class(app(AiDefaults::class), app(AiProviderService::class), app(AppDocs::class), $verdict) extends BuildCritic
    {
        public function __construct(
            AiDefaults $defaults,
            AiProviderService $providers,
            AppDocs $docs,
            private ?array $verdict,
        ) {
            parent::__construct($defaults, $providers, $docs);
        }

        protected function attempt(
            string $request,
            string $sheet,
            User $user,
            string $model,
            App $app,
            ?string $conversationId,
        ): ?array {
            return $this->verdict;
        }
    };
}

it('queues a review turn when an applied turn was never reviewed', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();

    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        2,
    );

    $queued = BuilderMessage::query()
        ->where('conversation_id', $conv->id)->where('role', 'user')->first();

    expect($queued)->not->toBeNull()
        ->and($queued->content)->toContain('critique_build')
        // The verbatim request is the whole point: a paraphrase hides the gap.
        ->and($queued->content)->toContain('sin parafrasear');
});

it('leaves a landing alone — that one answers to the design gate', function () {
    Queue::fake();
    [$conv] = reviewRailSetup('landing');

    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        2,
    );

    expect(BuilderMessage::where('conversation_id', $conv->id)->where('role', 'user')->exists())->toBeFalse();
});

it('does not fire on a turn that built nothing', function () {
    Queue::fake();
    [$conv] = reviewRailSetup('app', 'none');

    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        2,
    );

    expect(BuilderMessage::where('conversation_id', $conv->id)->where('role', 'user')->exists())->toBeFalse();
});

it('stops once the conversation has a clean verdict', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    $conv->forceFill(['build_reviewed_at' => now()])->save();

    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        2,
    );

    expect(BuilderMessage::where('conversation_id', $conv->id)->where('role', 'user')->exists())->toBeFalse();
});

it('respects Detener', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    app(BuilderCancellation::class)->request($conv);

    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        2,
    );

    expect(BuilderMessage::where('conversation_id', $conv->id)->where('role', 'user')->exists())->toBeFalse();
});

it('runs out of budget rather than looping a build forever', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();

    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        0,
    );

    expect(BuilderMessage::where('conversation_id', $conv->id)->where('role', 'user')->exists())->toBeFalse();
});

it('a clean verdict retires the rail', function () {
    [$conv, , $app, $user] = reviewRailSetup();

    $tool = new CritiqueBuildTool($app, railCritic([
        'complete' => true, 'missing' => [], 'unrequested' => [], 'summary' => 'Answers the request.',
    ]), $user, $conv->id);

    $tool->handle(new ToolRequest(['request' => 'Una app de órdenes.']));

    expect($conv->fresh()->build_reviewed_at)->not->toBeNull();
});

it('a verdict with gaps does not retire the rail', function () {
    [$conv, , $app, $user] = reviewRailSetup();

    $tool = new CritiqueBuildTool($app, railCritic([
        'complete' => false, 'missing' => ['the signature is a text field'], 'unrequested' => [], 'summary' => 'Incomplete.',
    ]), $user, $conv->id);

    $tool->handle(new ToolRequest(['request' => 'Firma con el dedo.']));

    expect($conv->fresh()->build_reviewed_at)->toBeNull();
});

it('a review that never ran does not retire the rail', function () {
    // The outcome the rail exists to survive: if 'failed' stamped, one dead
    // critic model would silently pass every build after it.
    [$conv, , $app, $user] = reviewRailSetup();

    $tool = new CritiqueBuildTool($app, railCritic(null), $user, $conv->id);
    $tool->handle(new ToolRequest(['request' => 'Algo.']));

    expect($conv->fresh()->build_reviewed_at)->toBeNull();
});
