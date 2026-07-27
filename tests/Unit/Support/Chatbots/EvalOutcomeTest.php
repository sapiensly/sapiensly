<?php

use App\Support\Chatbots\Evaluation\EvalCase;
use App\Support\Chatbots\Evaluation\EvalOutcome;

/**
 * The grader itself has to be trustworthy before its score means anything: this
 * harness exists to be run before and after a prompt change, and a grader that
 * disagrees with itself turns that comparison into noise.
 */
it('passes an answer that carries the material', function () {
    $case = new EvalCase(
        id: 'shipping',
        question: '¿Cuánto tarda el envío?',
        context: 'La entrega estándar tarda 3 días hábiles.',
        mustContain: ['3'],
    );

    expect(EvalOutcome::grade($case, 'Tarda 3 días hábiles.')->passed())->toBeTrue();
});

it('catches an answer that dropped the fact it was given', function () {
    $case = new EvalCase(
        id: 'shipping',
        question: '¿Cuánto tarda el envío?',
        context: 'La entrega estándar tarda 3 días hábiles.',
        mustContain: ['3 días'],
    );

    $outcome = EvalOutcome::grade($case, 'El envío es rápido, no te preocupes.');

    expect($outcome->passed())->toBeFalse()
        ->and($outcome->failures[0])->toContain('did not carry');
});

/**
 * The failure mode the whole grounding work exists to stop: a confident number
 * that was never in the material.
 */
it('catches an invented figure', function () {
    $case = new EvalCase(
        id: 'annual-price',
        question: '¿Cuánto cuesta el plan anual?',
        context: 'El plan mensual cuesta $349 MXN. El anual no está disponible.',
        mustNotContain: ['$4,188'],
        mustRefuse: true,
    );

    $outcome = EvalOutcome::grade($case, 'El plan anual cuesta $4,188 MXN al año.');

    expect($outcome->passed())->toBeFalse()
        ->and($outcome->failures)->toHaveCount(2);
});

it('accepts a refusal in either language the product speaks', function () {
    $case = new EvalCase(id: 'crypto', question: '¿Aceptan cripto?', mustRefuse: true);

    expect(EvalOutcome::grade($case, 'No tengo esa información en el expediente.')->passed())->toBeTrue()
        ->and(EvalOutcome::grade($case, "That isn't something I have on file.")->passed())->toBeTrue();
});

/**
 * A bot that refuses everything would score perfectly on the refusal cases and
 * be useless, so the check runs in both directions.
 */
it('catches a bot that refused something the material did answer', function () {
    $case = new EvalCase(
        id: 'cancel',
        question: '¿Puedo cancelar?',
        context: 'Puedes cancelar en cualquier momento.',
        mustContain: ['cancelar'],
    );

    $outcome = EvalOutcome::grade($case, 'No tengo esa información, lo siento.');

    expect($outcome->passed())->toBeFalse()
        ->and($outcome->failures)->toContain('refused a question the material did answer');
});

it('grades the same answer the same way every time', function () {
    $case = new EvalCase(
        id: 'shipping',
        question: '¿Cuánto tarda?',
        context: 'Tarda 3 días.',
        mustContain: ['3'],
        mustNotContain: ['mismo día'],
    );

    // Determinism is the reason the grader is phrase-level rather than a model
    // call: a judge would score this differently on two runs.
    $first = EvalOutcome::grade($case, 'Tarda 3 días hábiles.');
    $second = EvalOutcome::grade($case, 'Tarda 3 días hábiles.');

    expect($first->failures)->toBe($second->failures);
});

/**
 * Some facts have more than one correct wording, and demanding one exact phrase
 * fails a perfectly grounded answer — which sends someone hunting a bug in the
 * bot instead of in the case. That happened on the very first real run.
 */
it('accepts any one of several wordings when the case allows it', function () {
    $case = new EvalCase(
        id: 'handoff',
        question: '¿Me pueden llamar?',
        mustContainAny: ['correo', 'email'],
    );

    expect(EvalOutcome::grade($case, 'Te dejo mi correo y te escriben.')->passed())->toBeTrue()
        ->and(EvalOutcome::grade($case, 'Déjame tu email.')->passed())->toBeTrue();
});

it('still fails when none of the accepted wordings appear', function () {
    $case = new EvalCase(
        id: 'handoff',
        question: '¿Me pueden llamar?',
        mustContainAny: ['correo', 'email'],
    );

    $outcome = EvalOutcome::grade($case, 'Claro, te transfiero ahora mismo.');

    expect($outcome->passed())->toBeFalse()
        ->and($outcome->failures[0])->toContain('said none of');
});

it('reads a case from the set file shape', function () {
    $case = EvalCase::fromArray([
        'id' => 'warranty',
        'question' => '¿Cuánta garantía?',
        'context' => '12 meses.',
        'must_contain' => ['12'],
        'must_not_contain' => ['24'],
        'must_refuse' => false,
    ]);

    expect($case->id)->toBe('warranty')
        ->and($case->mustContain)->toBe(['12'])
        ->and($case->mustNotContain)->toBe(['24'])
        ->and($case->mustRefuse)->toBeFalse();
});
