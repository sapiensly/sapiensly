<?php

use App\Models\App;
use App\Models\BuilderConversation;
use App\Models\BuilderMessage;
use App\Models\BuildFinding;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Apps\BuildCritic;
use App\Services\Apps\Docs\AppDocs;
use App\Services\Builder\BuilderAiService;
use App\Services\Builder\BuilderCancellation;
use Illuminate\Support\Facades\Queue;

/**
 * The closing review runs HERE, once per applied turn, and not as a tool the
 * model can call.
 *
 * It was a tool first, and the model spent six passes on one build — the
 * product of two unbounded knobs, its own discretion inside a turn plus this
 * rail queueing more turns. Those six passes cost more than half the build.
 * Server-side, the count is one per applied turn by construction, and the
 * queued turn carries the FINDINGS rather than an instruction to go and look.
 */
function reviewRailSetup(string $kind = 'app', string $turnStatus = 'applied'): array
{
    $user = User::factory()->create();
    $app = App::factory()->create(['user_id' => $user->id]);
    $app->forceFill(['kind' => $kind])->save();
    $conv = BuilderConversation::create(['app_id' => $app->id, 'user_id' => $user->id, 'status' => 'active']);

    // The rail reviews against the conversation's own request text.
    BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'user',
        'status' => 'none', 'content' => 'Quiero la firma del cliente con el dedo.',
    ]);
    $finished = BuilderMessage::create([
        'conversation_id' => $conv->id, 'role' => 'assistant',
        'status' => $turnStatus, 'content' => 'Listo.',
    ]);

    return [$conv, $finished, $app, $user];
}

/** Binds a critic whose verdict is fixed, so the RAIL is what gets tested. */
function fakeCritic(?array $verdict): void
{
    app()->instance(BuildCritic::class, new class(app(AiDefaults::class), app(AiProviderService::class), app(AppDocs::class), $verdict) extends BuildCritic
    {
        public function __construct(AiDefaults $d, AiProviderService $p, AppDocs $docs, private ?array $verdict)
        {
            parent::__construct($d, $p, $docs);
        }

        public function critique(App $app, string $request, User $user, ?string $explicitModel = null, ?string $conversationId = null, ?array $draftManifest = null): array
        {
            return $this->verdict ?? ['critic' => 'failed', 'reason' => 'no model'];
        }
    });
}

function runRail(BuilderConversation $conv, int $budget = 2): void
{
    app(BuilderAiService::class)->continueForBuildCritic(
        BuilderMessage::where('conversation_id', $conv->id)->where('role', 'assistant')->first(),
        null,
        $budget,
    );
}

function queuedRailTurn(BuilderConversation $conv): ?BuilderMessage
{
    return BuilderMessage::query()
        ->where('conversation_id', $conv->id)->where('role', 'user')
        ->where('content', 'like', '%riel de cierre%')->first();
}

it('queues the findings themselves, not an instruction to go and look', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    fakeCritic([
        'critic' => 'ok', 'complete' => false,
        'missing' => ['la firma es un campo de texto, no una captura'],
        'unrequested' => ['una página «Punto de venta»'],
        'summary' => 'Incompleta.',
    ]);

    runRail($conv);

    $queued = queuedRailTurn($conv);

    expect($queued)->not->toBeNull()
        ->and($queued->content)->toContain('la firma es un campo de texto')
        ->and($queued->content)->toContain('Punto de venta')
        // And never the old "go call the tool" phrasing — the model has no tool.
        ->and($queued->content)->not->toContain('critique_build');
});

it('retires the rail when nothing is missing and nothing was invented', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    fakeCritic(['critic' => 'ok', 'complete' => true, 'missing' => [], 'unrequested' => [], 'summary' => 'Bien.']);

    runRail($conv);

    expect($conv->fresh()->build_reviewed_at)->not->toBeNull()
        ->and(queuedRailTurn($conv))->toBeNull();
});

it('retires the rail but still forwards an invention as a last errand', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    fakeCritic([
        'critic' => 'ok', 'complete' => true, 'missing' => [],
        'unrequested' => ['una página «Punto de venta»'], 'summary' => 'Completa, con un extra.',
    ]);

    runRail($conv);

    // Nothing is missing, so the review is done — an invention is a judgement
    // call, not grounds to keep reviewing.
    expect($conv->fresh()->build_reviewed_at)->not->toBeNull()
        ->and(queuedRailTurn($conv)?->content)->toContain('Punto de venta');
});

it('never stamps on a review that did not run', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    fakeCritic(null); // → critic: 'failed'

    runRail($conv);

    expect($conv->fresh()->build_reviewed_at)->toBeNull()
        ->and(queuedRailTurn($conv))->toBeNull();
});

it('leaves a landing alone — that one answers to the design gate', function () {
    Queue::fake();
    [$conv] = reviewRailSetup('landing');
    fakeCritic(['critic' => 'ok', 'complete' => false, 'missing' => ['algo'], 'unrequested' => [], 'summary' => '']);

    runRail($conv);

    expect(queuedRailTurn($conv))->toBeNull();
});

it('does not fire on a turn that built nothing', function () {
    Queue::fake();
    [$conv] = reviewRailSetup('app', 'none');
    fakeCritic(['critic' => 'ok', 'complete' => false, 'missing' => ['algo'], 'unrequested' => [], 'summary' => '']);

    runRail($conv);

    expect(queuedRailTurn($conv))->toBeNull();
});

it('stops once the conversation has a clean verdict', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    $conv->forceFill(['build_reviewed_at' => now()])->save();
    fakeCritic(['critic' => 'ok', 'complete' => false, 'missing' => ['algo'], 'unrequested' => [], 'summary' => '']);

    runRail($conv);

    expect(queuedRailTurn($conv))->toBeNull();
});

it('respects Detener', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    app(BuilderCancellation::class)->request($conv);
    fakeCritic(['critic' => 'ok', 'complete' => false, 'missing' => ['algo'], 'unrequested' => [], 'summary' => '']);

    runRail($conv);

    expect(queuedRailTurn($conv))->toBeNull();
});

it('keeps the verdict in the failure ledger, coded by direction', function () {
    Queue::fake();
    [$conv, , $app] = reviewRailSetup();
    fakeCritic([
        'critic' => 'ok', 'complete' => false,
        'missing' => ['la firma es un campo de texto, no una captura'],
        'unrequested' => ['una página «Punto de venta»'],
        'summary' => 'Incompleta.',
    ]);

    runRail($conv);

    // The critic is the only signal that judges the app against what was ASKED
    // rather than against the schema, which is what makes it worth counting.
    $findings = BuildFinding::where('app_id', $app->id)->get();

    expect($findings)->toHaveCount(2)
        ->and($findings->pluck('signal')->unique()->all())->toBe([BuildFinding::SIGNAL_CRITIC])
        ->and($findings->firstWhere('code', BuildFinding::CODE_MISSING)?->detail)
        ->toContain('la firma es un campo de texto')
        ->and($findings->firstWhere('code', BuildFinding::CODE_UNREQUESTED)?->detail)
        ->toContain('Punto de venta')
        ->and($findings->first()->conversation_id)->toBe($conv->id)
        // The BUILDER's model, not the critic's: the ledger answers which
        // builder leaves more behind, and the critic's spend is already in
        // ai_usage_events.
        ->and($findings->first()->model)->not->toBeNull();
});

it('records nothing when the review comes back clean', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    fakeCritic(['critic' => 'ok', 'complete' => true, 'missing' => [], 'unrequested' => [], 'summary' => 'Bien.']);

    runRail($conv);

    // A ledger of failures, not of runs.
    expect(BuildFinding::count())->toBe(0);
});

it('runs out of budget rather than looping a build forever', function () {
    Queue::fake();
    [$conv] = reviewRailSetup();
    fakeCritic(['critic' => 'ok', 'complete' => false, 'missing' => ['algo'], 'unrequested' => [], 'summary' => '']);

    runRail($conv, budget: 0);

    expect(queuedRailTurn($conv))->toBeNull();
});
