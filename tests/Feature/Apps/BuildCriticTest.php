<?php

use App\Models\App;
use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Apps\BuildCritic;
use App\Services\Apps\Docs\AppDocs;

/**
 * The closing review of an app build.
 *
 * It exists for a failure the validator cannot see: both live builds of one
 * brief ended with the model narrating success over work it had skipped — an
 * edit abandoned after five rejected patches, capture fields wired into the
 * wrong page — and both apps validated clean. The other direction is the one
 * nobody looks for: those same builds invented a "Punto de venta" page in a
 * field-service app, which no rule flags because a page is a legal page.
 */
function criticWith(?array $verdict): BuildCritic
{
    return new class(app(AiDefaults::class), app(AiProviderService::class), app(AppDocs::class), $verdict) extends BuildCritic
    {
        public array $modelsTried = [];

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
            $this->modelsTried[] = $model;

            return $this->verdict;
        }
    };
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->testApp = App::factory()->create(['user_id' => $this->user->id]);
});

it('reports what was asked for and is not there', function () {
    $critic = criticWith([
        'complete' => false,
        'missing' => ["the client's signature is a text field, not a signature capture"],
        'unrequested' => [],
        'summary' => 'The capture fields were never wired.',
    ]);

    $verdict = $critic->critique($this->testApp, 'Quiero la firma del cliente con el dedo.', $this->user);

    expect($verdict['critic'])->toBe('ok')
        ->and($verdict['complete'])->toBeFalse()
        ->and($verdict['missing'][0])->toContain('signature');
});

it('reports subject matter nobody asked for', function () {
    $critic = criticWith([
        'complete' => true,
        'missing' => [],
        'unrequested' => ['a «Punto de venta» page with a cart, in a field-service app'],
        'summary' => 'Answers the request, plus a page from another product.',
    ]);

    $verdict = $critic->critique($this->testApp, 'Órdenes de servicio en campo.', $this->user);

    // An invention does not make the build incomplete — it is a separate
    // finding, and conflating them would let one hide the other.
    expect($verdict['complete'])->toBeTrue()
        ->and($verdict['unrequested'][0])->toContain('Punto de venta');
});

it('never reports a failed review as a clean one', function () {
    // The landing gate learned this the hard way: a director that timed out
    // silently counted as approval. 'failed' is retryable; it is not a pass.
    $verdict = criticWith(null)->critique($this->testApp, 'Lo que sea.', $this->user);

    expect($verdict['critic'])->toBe('failed')
        ->and($verdict)->not->toHaveKey('complete');
});

it('skips, rather than reviews against nothing, when there is no request', function () {
    $verdict = criticWith(['complete' => true, 'missing' => [], 'unrequested' => [], 'summary' => ''])
        ->critique($this->testApp, '   ', $this->user);

    expect($verdict['critic'])->toBe('skipped');
});

it('falls back to the next model when the first produces no verdict', function () {
    $critic = criticWith(null);
    $critic->critique($this->testApp, 'Algo.', $this->user);

    // Two attempts, no more: a third pass has never changed a verdict, and an
    // unbounded chain turns one review into a bill.
    expect(count($critic->modelsTried))->toBeLessThanOrEqual(2);
});

it('reviews with the builder chain when no critic model is configured', function () {
    $candidates = app(BuildCritic::class)->criticCandidates();

    expect($candidates)->not->toBeEmpty()
        ->and($candidates)->toBe(array_slice(array_unique($candidates), 0, 2));
});

it('puts an explicit model ahead of the configured one', function () {
    $candidates = app(BuildCritic::class)->criticCandidates('claude-explicit-1');

    expect($candidates[0])->toBe('claude-explicit-1');
});
