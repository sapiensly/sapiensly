<?php

use App\Support\Locale\Inflector;

it('singularizes Spanish vowel-stem plurals by dropping the -s', function (string $plural, string $singular) {
    expect(Inflector::singular($plural, 'es'))->toBe($singular);
})->with([
    ['Productos', 'Producto'],
    ['Categorías', 'Categoría'],
    ['Movimientos', 'Movimiento'],
    ['Clientes', 'Cliente'],
    ['Ventas', 'Venta'],
    ['Facturas', 'Factura'],
]);

it('singularizes Spanish consonant-stem (-es) plurals', function (string $plural, string $singular) {
    expect(Inflector::singular($plural, 'es'))->toBe($singular);
})->with([
    ['Proveedores', 'Proveedor'],
    ['Vendedores', 'Vendedor'],
    ['Materiales', 'Material'],
    ['Papeles', 'Papel'],
    ['Roles', 'Rol'],
    ['Actividades', 'Actividad'],
    ['Direcciones', 'Dirección'],
    ['Luces', 'Luz'],
]);

it('drops the esdrújula accent on -enes plurals', function () {
    expect(Inflector::singular('Órdenes', 'es'))->toBe('Orden');
    expect(Inflector::singular('Imágenes', 'es'))->toBe('Imagen');
});

it('singularizes only the head noun of a compound "X de Y" name', function () {
    expect(Inflector::singular('Órdenes de Compra', 'es'))->toBe('Orden de Compra');
    expect(Inflector::singular('Líneas de Pedido', 'es'))->toBe('Línea de Pedido');
});

it('leaves already-singular Spanish nouns untouched', function () {
    expect(Inflector::singular('Producto', 'es'))->toBe('Producto');
    expect(Inflector::singular('Categoría', 'es'))->toBe('Categoría');
});

it('falls back to the English inflector for non-Spanish locales', function () {
    expect(Inflector::singular('Products', 'en'))->toBe('Product');
    expect(Inflector::singular('Categories', 'en'))->toBe('Category');
});

it('handles empty and whitespace input', function () {
    expect(Inflector::singular('', 'es'))->toBe('');
    expect(Inflector::singular('   ', 'es'))->toBe('');
});
