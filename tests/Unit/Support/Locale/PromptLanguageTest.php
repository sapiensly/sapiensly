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

it('returns null when the prompt is too short or ambiguous to tell', function (string $prompt) {
    expect(PromptLanguage::detect($prompt))->toBeNull();
})->with([
    'x',
    '',
    'NPS',
    '2026',
]);
