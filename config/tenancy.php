<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Runtime role names
    |--------------------------------------------------------------------------
    |
    | The least-privilege Postgres roles the schema/grant migrations target. They
    | are deliberately SEPARATE from the connection usernames (config/database.php)
    | so that environments which connect as a different user — e.g. the test suite
    | connects the platform/tenant connections as the owner to bypass RLS — still
    | create and grant the real roles. In production these match the connection
    | usernames. On Supabase, override if the roles are named differently.
    |
    */

    'platform_role' => env('PLATFORM_DB_ROLE', 'platform_app'),

    'tenant_role' => env('TENANT_DB_ROLE', 'tenant_app'),

];
