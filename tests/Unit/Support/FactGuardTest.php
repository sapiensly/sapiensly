<?php

use App\Support\Ai\FactGuard;

/**
 * The model may change the voice, never the facts. Until this guard existed, one
 * of the three LLM copy paths enforced that and two did not.
 */
it('rejects a rewrite that quietly drops a figure', function () {
    $original = '3 de 15 motivos concentran el 47% del total.';

    // Same claim, executive voice, numbers intact.
    expect(FactGuard::keepsNumbers($original, 'El 47% del volumen vive en 3 de 15 motivos.'))->toBeTrue()
        // The vaguer sentence a model reaches for — and the exact failure this
        // guard exists to catch: it reads better and says less.
        ->and(FactGuard::keepsNumbers($original, 'La mayoría del volumen viene de unos pocos motivos.'))->toBeFalse()
        // Losing ONE number is losing the fact.
        ->and(FactGuard::keepsNumbers($original, '3 motivos concentran casi la mitad.'))->toBeFalse();
});

it('reads the same figure written two ways as one', function () {
    // A rewrite must not be rejected for punctuation.
    expect(FactGuard::keepsNumbers('Suma 1234 pesos', 'Suma 1,234 pesos'))->toBeTrue()
        ->and(FactGuard::keepsNumbers('El backlog llegó a 40.0', 'El backlog llegó a 40'))->toBeTrue()
        // A number at the end of a sentence keeps its meaning, not its full stop.
        ->and(FactGuard::keepsNumbers('Creció un 12.', 'Subió 12 puntos'))->toBeTrue();
});

it('catches a number the data never contained', function () {
    $facts = '{"top":3,"pct":47,"total_categorias":15}';

    expect(FactGuard::onlyKnownNumbers('3 motivos concentran el 47%.', $facts))->toBeTrue()
        // The invented figure — the one preservation-checking can never catch,
        // because there was no original sentence to preserve it from.
        ->and(FactGuard::onlyKnownNumbers('Perdemos 1.2M al año por esto.', $facts))->toBeFalse();
});

it('rejects a slide whose values changed, however good the prose is', function () {
    $slide = [
        'layout' => 'big_number',
        'value' => '412',
        'label' => 'Backlog',
        'context' => 'Sube desde 380.',
    ];

    // The prose was stale; fixing it is exactly what the model was asked to do.
    $fixed = $slide;
    $fixed['context'] = 'Sube desde 380 — el pico del trimestre.';
    expect(FactGuard::keepsValues($slide, $fixed, ['context']))->toBeTrue();

    // The prompt says "NEVER touch values". Nothing used to make that true.
    $tampered = $slide;
    $tampered['value'] = '520';
    expect(FactGuard::keepsValues($slide, $tampered, ['context']))->toBeFalse();

    // Nor may it quietly drop a field it was not invited to touch.
    $dropped = $slide;
    unset($dropped['label']);
    expect(FactGuard::keepsValues($slide, $dropped, ['context']))->toBeFalse();
});

it('fails closed: an empty rewrite is never an improvement', function () {
    expect(FactGuard::safeRewrite('El 47% en 3 motivos.', ''))->toBeNull()
        ->and(FactGuard::safeRewrite('El 47% en 3 motivos.', null))->toBeNull()
        ->and(FactGuard::safeRewrite('El 47% en 3 motivos.', 'Casi la mitad, en pocos motivos.'))->toBeNull()
        ->and(FactGuard::safeRewrite('El 47% en 3 motivos.', '3 motivos cargan el 47%.'))->toBe('3 motivos cargan el 47%.');
});
