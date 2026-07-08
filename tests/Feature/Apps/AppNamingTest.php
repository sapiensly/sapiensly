<?php

use App\Models\App;
use App\Models\User;
use App\Support\Apps\AppNaming;

/**
 * An app names itself from its first builder prompt (like a chat titling itself),
 * so it can open unnamed straight into the Builder.
 */
it('distills a human name from a build prompt, dropping the lead verb', function () {
    expect(AppNaming::nameFromPrompt('crea un dashboard para analizar el NPS de Yuhu'))
        ->toBe('Dashboard para analizar el NPS de Yuhu')
        ->and(AppNaming::nameFromPrompt('hazme un CRM de ventas'))->toBe('CRM de ventas')
        ->and(AppNaming::nameFromPrompt('build a support desk'))->toBe('Support desk');
});

it('caps a long name at a word boundary and sentence-cases it', function () {
    $name = AppNaming::nameFromPrompt('quiero un tablero con muchísimos indicadores de operación logística nacional e internacional detallados');
    expect(mb_strlen($name))->toBeLessThanOrEqual(60)
        ->and($name)->not->toEndWith(' ')
        ->and($name[0])->toBe(mb_strtoupper($name[0]));
});

it('returns null when there is nothing usable to name', function () {
    expect(AppNaming::nameFromPrompt('   '))->toBeNull()
        ->and(AppNaming::nameFromPrompt('crea un'))->toBeNull();
});

it('forces a description down to a single sentence', function () {
    expect(AppNaming::firstSentence('Salud del NPS de Yuhu. Además muestra tickets por semana.'))
        ->toBe('Salud del NPS de Yuhu.')
        ->and(AppNaming::firstSentence('Evolución del NPS por segmento')) // no terminator → whole
        ->toBe('Evolución del NPS por segmento')
        ->and(AppNaming::firstSentence("Línea uno.\nLínea dos."))->toBe('Línea uno.')
        ->and(AppNaming::firstSentence('  '))->toBe('');
});

it('builds a bounded, sentence-cased description from the prompt', function () {
    expect(AppNaming::descriptionFromPrompt('analiza el otd semanal de yuhu'))
        ->toBe('Analiza el otd semanal de yuhu');
});

it('derives a unique slug in the manifest grammar', function () {
    $user = User::factory()->create();
    App::factory()->create(['user_id' => $user->id, 'organization_id' => null, 'slug' => 'dashboard_de_ventas']);

    $slug = AppNaming::uniqueSlug('Dashboard de ventas', null);

    expect($slug)->toBe('dashboard_de_ventas_2')      // collided → suffixed
        ->and(AppNaming::uniqueSlug('99 Bad Start!', null))->toStartWith('app'); // must start with a letter
});
