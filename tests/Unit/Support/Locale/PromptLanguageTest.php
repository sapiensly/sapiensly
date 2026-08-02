<?php

use App\Support\Locale\PromptLanguage;

it('detects Spanish from function words and diacritics', function (string $prompt) {
    expect(PromptLanguage::detect($prompt))->toBe('es');
})->with([
    'crea un dashboard de NPS de yuhu',
    'dashboard de tickets semanales',
    'quiero un tablero de ventas por región',
    'muéstrame el análisis de clientes',
    'análisis de tickets',
]);

it('detects English from function words', function (string $prompt) {
    expect(PromptLanguage::detect($prompt))->toBe('en');
})->with([
    'create a dashboard of weekly tickets',
    'build me a sales scoreboard by region',
    'show the customer analysis with trends',
]);

it('detects Portuguese from function words and nasal diacritics', function (string $prompt) {
    expect(PromptLanguage::detect($prompt))->toBe('pt');
})->with([
    'crie um sistema de gestão de estoque para minha loja',
    'preciso de um painel com métricas de vendas',
    'gere um aplicativo para meus pedidos e clientes',
]);

it('detects French from function words and diacritics', function (string $prompt) {
    expect(PromptLanguage::detect($prompt))->toBe('fr');
})->with([
    'créer un système de gestion des commandes pour mon restaurant',
    'je veux un tableau de bord avec les ventes par région',
    'génère une application pour mes rendez-vous',
]);

it('returns null when the prompt is too short or ambiguous to tell', function (string $prompt) {
    expect(PromptLanguage::detect($prompt))->toBeNull();
})->with([
    'x',
    '',
    'NPS',
    '2026',
]);

it('reads a real brief in the language it was written in', function () {
    // Portuguese and French were both read as Spanish, so a Brazilian school
    // and a French law firm would have been handed an app in the wrong
    // language — and, since currency and timezone follow it, in the wrong money
    // too. The plain word lists each hold their words once, but the TEXTS
    // overlap: pt and fr are full of "de", "que", "para", "la", which sit in
    // the Spanish list, and a long brief accumulates them by sheer length.
    $suite = require dirname(__DIR__, 4).'/resources/benchmarks/app-suite.php';

    $wrong = [];
    foreach ($suite as $case) {
        $expected = substr($case['locale'], 0, 2);
        $detected = PromptLanguage::detect(
            $case['name'].' '.$case['description'],
        );

        if ($detected !== $expected) {
            $wrong[] = "{$case['key']}: expected {$expected}, read as ".($detected ?? 'nothing');
        }
    }

    expect($wrong)->toBeEmpty(implode('; ', $wrong));
});
