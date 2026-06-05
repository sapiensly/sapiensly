<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use ReflectionProperty;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // In tests the platform/tenant runtime connections target the same
        // database as the default `pgsql` connection. Alias them to the *same
        // Connection instance* as the default so every model — platform or
        // tenant — runs in one session AND shares transaction-level tracking.
        // This lets them see each other's rows, satisfy cross-schema FKs, and
        // participate in the single RefreshDatabase transaction (including
        // nested transactions/savepoints opened on the tenant connection). The
        // default search_path spans both schemas so unqualified tables resolve.
        if (config('database.default') === 'pgsql') {
            $db = $this->app['db'];
            $default = $db->connection();

            $property = new ReflectionProperty($db, 'connections');
            $property->setAccessible(true);
            $connections = $property->getValue($db);
            $connections['platform'] = $default;
            $connections['tenant'] = $default;
            $property->setValue($db, $connections);
        }
    }
}
