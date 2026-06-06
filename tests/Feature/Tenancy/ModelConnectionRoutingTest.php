<?php

use App\Models\Chat;
use App\Models\Concerns\UsesPlatformConnection;
use App\Models\Concerns\UsesTenantConnection;
use App\Support\Tenancy\Schemas;
use Illuminate\Support\Str;

/**
 * Guards the schema-split connection routing. The regression these cover:
 * Eloquent's HasRelationships::newRelatedInstance() copies the parent's
 * connection onto a related model that declares none, so a platform model
 * (e.g. User) loaded as a relation of a tenant model (e.g. $chat->user) would
 * inherit the `tenant` connection and fail with `relation "users" does not
 * exist`. UsesPlatformConnection pins those models so the hijack can't happen.
 */
it('routes every platform model to the platform connection', function () {
    $platformModels = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path) => 'App\\Models\\'.Str::beforeLast(basename($path), '.php'))
        ->filter(fn (string $class) => class_exists($class))
        ->reject(fn (string $class) => in_array(UsesTenantConnection::class, class_uses_recursive($class), true));

    expect($platformModels)->not->toBeEmpty();

    foreach ($platformModels as $class) {
        expect((new $class)->getConnectionName())
            ->toBe(Schemas::PLATFORM_CONNECTION, "{$class} must use the platform connection");

        expect(class_uses_recursive($class))
            ->toContain(UsesPlatformConnection::class);
    }
});

it('keeps a platform model on platform when loaded as a tenant relation', function () {
    $chat = new Chat;

    expect($chat->getConnectionName())->toBe(Schemas::TENANT_CONNECTION);

    // newRelatedInstance() runs through the relation's query builder; the
    // related model must NOT inherit the tenant parent's connection.
    expect($chat->user()->getRelated()->getConnectionName())->toBe(Schemas::PLATFORM_CONNECTION);
    expect($chat->agent()->getRelated()->getConnectionName())->toBe(Schemas::PLATFORM_CONNECTION);
});
