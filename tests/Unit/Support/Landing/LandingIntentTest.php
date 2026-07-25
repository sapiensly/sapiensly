<?php

use App\Support\Landing\LandingIntent;

it('matches strong landing/website markers, accent- and case-insensitive', function (string $text) {
    expect(LandingIntent::matches($text))->toBeTrue();
})->with([
    'Quiero una landing para mi vivero',
    'una LANDING PAGE agéntica',
    'hazme una página web para el estudio',
    'una pagina web sencilla',
    'necesito un sitio web con formulario',
    'build me a website for my bakery',
    'a one-pager for the launch',
    'un micrositio para el evento',
    'una página de aterrizaje para la campaña',
]);

it('matches bare pagina/sitio only when the text is not an app build', function () {
    expect(LandingIntent::matches('hazme una página para mi taquería'))->toBeTrue()
        ->and(LandingIntent::matches('un sitio para mostrar mis proyectos'))->toBeTrue()
        // App-ish context wins: these must scaffold, not compose landings.
        ->and(LandingIntent::matches('una app de inventario con una página por objeto'))->toBeFalse()
        ->and(LandingIntent::matches('crea una aplicación con páginas de clientes y pedidos'))->toBeFalse()
        ->and(LandingIntent::matches('un dashboard con página de resumen'))->toBeFalse();
});

it('does not match plain app/dashboard requests or empty text', function (?string $text) {
    expect(LandingIntent::matches($text))->toBeFalse();
})->with([
    'crea una app para gestionar pedidos de la cocina',
    'un CRUD de clientes con kanban',
    'analiza los tickets y hazme un dashboard',
    '',
    null,
]);
