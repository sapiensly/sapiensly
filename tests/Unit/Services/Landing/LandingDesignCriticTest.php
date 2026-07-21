<?php

use App\Models\User;
use App\Services\Ai\AiDefaults;
use App\Services\AiProviderService;
use App\Services\Landing\LandingDesignCritic;
use Laravel\Ai\Files\StoredImage;

function designCritic(): LandingDesignCritic
{
    // The deterministic path never touches the AI deps (user is null below), so
    // bare mocks with no expectations are enough — this stays a pure unit test.
    return new LandingDesignCritic(
        Mockery::mock(AiDefaults::class),
        Mockery::mock(AiProviderService::class),
    );
}

const RICH_CSS = '.ld{--bg:#06070c;--panel:#0f1220;--cool:#4da3ff;--warm:#ffa56b;--ink:#eaf0ff;background:var(--bg);color:var(--ink);} .ld h1{font-size:clamp(2.5rem,6vw,4.5rem);letter-spacing:-.03em;line-height:.98;} .ld .eyebrow{text-transform:uppercase;letter-spacing:.2em;font-size:.72rem;color:var(--cool);} .ld .fc{border:1px solid #223049;border-radius:14px;transition:transform .2s;} .ld .fc:hover{transform:translateY(-3px);} .ld .btn{background:linear-gradient(180deg,#7dbcff,#2e7bff);}';

const RICH_HTML = "<div class='ld'><section class='hero' data-sp-motion='ambient-field'><div class='wrap' data-sp-reveal><span class='eyebrow'>Landing</span><h1 class='title'>La landing que se atiende sola</h1><p class='sub'>Una descripcion suficientemente larga para superar el piso de doscientos caracteres del critico.</p></div></section></div>";

it('passes the deterministic floor for a bespoke landing (and ships when the director is unavailable)', function () {
    $r = designCritic()->critique('demo booking for a B2B SaaS', RICH_HTML, RICH_CSS, null);

    expect($r['must_fix'])->toBe([])
        ->and($r['ship'])->toBeTrue()
        ->and($r['judged_by'])->toBe('deterministic');
});

it('blocks a landing with no bespoke css, no display type and no motion', function () {
    $r = designCritic()->critique('x', '<div>hi</div>', '', null);

    expect($r['ship'])->toBeFalse()
        ->and($r['must_fix'])->not->toBeEmpty();

    $joined = implode(' | ', $r['must_fix']);
    expect($joined)->toContain('custom_css')
        ->toContain('display type')
        ->toContain('motion')
        ->toContain('html sections');
});

it('requires a confident display type even when the css is otherwise present', function () {
    $flatCss = str_repeat('.pad{padding:1rem;} ', 30).'.a{font-size:1rem;color:#111827;} .b{color:#f8fafc;} .c{color:#6b7280;text-transform:uppercase;}';
    $r = designCritic()->deterministicTells(RICH_HTML, $flatCss);

    $joined = implode(' | ', $r['must_fix']);
    expect($joined)->toContain('display type')
        ->and($joined)->not->toContain('custom_css'); // css is long enough
});

it('reports generic tells: centered-everything and a timid palette', function () {
    $css = '.a{text-align:center}.b{text-align:center}.c{text-align:center}.d{text-align:center}.e{font-size:clamp(3rem,6vw,4rem)}';
    $r = designCritic()->deterministicTells(RICH_HTML, $css);

    $joined = implode(' | ', $r['tells']);
    expect($joined)->toContain('centered')
        ->toContain('palette');
});

/** A critic whose director pass is stubbed to a fixed verdict, to test the merge. */
function criticWithDirector(array $verdict): LandingDesignCritic
{
    return new class(Mockery::mock(AiDefaults::class), Mockery::mock(AiProviderService::class), $verdict) extends LandingDesignCritic
    {
        public function __construct($a, $b, private array $stub)
        {
            parent::__construct($a, $b);
        }

        protected function directorCritique(string $intent, string $html, string $css, ?User $user, ?string $modelOverride, ?StoredImage $screenshot = null, bool $screenshotIsCurrentDraft = false): ?array
        {
            return $this->stub;
        }
    };
}

it('ships when the director approves and the deterministic floor is clean', function () {
    $r = criticWithDirector(['ship' => true, 'score' => 93, 'must_fix' => [], 'direction' => [], 'strengths' => ['Bold, specific hero']])
        ->critique('x', RICH_HTML, RICH_CSS, null);

    expect($r['ship'])->toBeTrue()
        ->and($r['score'])->toBe(93)
        ->and($r['judged_by'])->toBe('design-director')
        ->and($r['strengths'])->toContain('Bold, specific hero');
});

it('does not ship when the director says revise, even for a bespoke page', function () {
    $r = criticWithDirector(['ship' => false, 'score' => 61, 'must_fix' => ['The hero is centered and generic — make it asymmetric.'], 'direction' => ['Add tension'], 'strengths' => []])
        ->critique('x', RICH_HTML, RICH_CSS, null);

    expect($r['ship'])->toBeFalse()
        ->and($r['score'])->toBe(61)
        ->and(implode(' ', $r['must_fix']))->toContain('asymmetric');
});

it('still blocks on a deterministic must_fix even if the director approves', function () {
    $r = criticWithDirector(['ship' => true, 'score' => 95, 'must_fix' => [], 'direction' => [], 'strengths' => []])
        ->critique('x', '<div>hi</div>', '', null);

    expect($r['ship'])->toBeFalse()
        ->and($r['must_fix'])->not->toBeEmpty();
});

it('converges: a high-score page ships and its remaining fixes become polish', function () {
    // Director says revise, but the score clears the ship threshold (85).
    $r = criticWithDirector(['ship' => false, 'score' => 88, 'must_fix' => ['Tighten the h1 tracking to -.05em'], 'direction' => ['Add a texture'], 'strengths' => []])
        ->critique('x', RICH_HTML, RICH_CSS, null);

    expect($r['ship'])->toBeTrue()
        ->and($r['converged'])->toBeTrue()
        ->and($r['must_fix'])->toBe([]) // the floor is clean; AI fixes demoted
        ->and(implode(' ', $r['direction']))->toContain('Tighten the h1 tracking');
});

it('converges after the max rounds even on a low score', function () {
    $r = criticWithDirector(['ship' => false, 'score' => 60, 'must_fix' => ['Bolder hero'], 'direction' => [], 'strengths' => []])
        ->critique('x', RICH_HTML, RICH_CSS, null, null, 3);

    expect($r['ship'])->toBeTrue()
        ->and($r['converged'])->toBeTrue()
        ->and($r['round'])->toBe(3)
        ->and(implode(' ', $r['direction']))->toContain('Bolder hero');
});

it('does not converge before the threshold or the round cap', function () {
    $r = criticWithDirector(['ship' => false, 'score' => 72, 'must_fix' => ['Add tension'], 'direction' => [], 'strengths' => []])
        ->critique('x', RICH_HTML, RICH_CSS, null, null, 2);

    expect($r['ship'])->toBeFalse()
        ->and($r['converged'])->toBeFalse()
        ->and(implode(' ', $r['must_fix']))->toContain('Add tension');
});
