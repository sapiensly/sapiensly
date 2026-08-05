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

it('reads a strong marker inside a field list as a field, not as intent', function () {
    // "sitio web" / "website" are ordinary columns on a customer or supplier
    // record. Matching them cost a real build: an eight-entity CRUD brief was
    // refused by scaffold_app and billed every turn to the landing model.
    expect(LandingIntent::matches(
        'Clientes: nombre, email de contacto, teléfono, sitio web, dirección, segmento.',
    ))->toBeFalse()
        ->and(LandingIntent::matches('Proveedores: nombre, website, RFC'))->toBeFalse()
        ->and(LandingIntent::matches('campos: razón social, sitio web.'))->toBeFalse()
        // Last item of the list, closed by a paren or a semicolon.
        ->and(LandingIntent::matches('(nombre, sitio web); teléfono'))->toBeFalse()
        ->and(LandingIntent::matches('nombre, dirección y sitio web'))->toBeTrue();
});

it('still matches a strong marker that names what is being built', function () {
    // The positional rule must not soften a real request just because the
    // sentence happens to contain commas.
    expect(LandingIntent::matches('quiero una landing, moderna y con formulario'))->toBeTrue()
        ->and(LandingIntent::matches('para el lanzamiento, necesito un sitio web'))->toBeTrue()
        ->and(LandingIntent::matches('una landing para mi app de inventario'))->toBeTrue();
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
