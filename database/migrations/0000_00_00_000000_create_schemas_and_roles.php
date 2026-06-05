<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Foundation migration for the platform/tenant database split.
 *
 * Creates the two schemas, the two least-privilege runtime roles, and the
 * grants/default-privileges that make every table the owner creates later
 * automatically accessible to the right runtime role — and ONLY that role.
 *
 * Isolation is structural: `platform_app` has USAGE on `platform` only and
 * `tenant_app` on `tenant` only, so neither can touch the other's schema.
 * RLS (added per tenant table) then isolates rows within the tenant schema.
 *
 * Runs as the owner (`pgsql`). Idempotent: on Supabase the roles already exist
 * so only the grants apply; locally the roles are created.
 */
return new class extends Migration
{
    /**
     * Always run against the owner connection regardless of the app default
     * (which is the least-privilege `platform` connection).
     */
    protected $connection = 'pgsql';

    private const PLATFORM_SCHEMA = 'platform';

    private const TENANT_SCHEMA = 'tenant';

    public function up(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        $owner = (string) config('database.connections.pgsql.username', 'postgres');

        // Role NAMES come from config/tenancy.php, not the connection usernames,
        // so the real least-privilege roles are created/granted even when a
        // connection authenticates as a different user (e.g. the test suite runs
        // the runtime connections as the owner).
        $platformRole = (string) config('tenancy.platform_role', 'platform_app');
        $platformPassword = (string) config('database.connections.platform.password', '');

        $tenantRole = (string) config('tenancy.tenant_role', 'tenant_app');
        $tenantPassword = (string) config('database.connections.tenant.password', '');

        DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::PLATFORM_SCHEMA);
        DB::statement('CREATE SCHEMA IF NOT EXISTS '.self::TENANT_SCHEMA);

        $this->ensureRole($platformRole, $platformPassword);
        $this->ensureRole($tenantRole, $tenantPassword);

        $this->grantSchema(self::PLATFORM_SCHEMA, $platformRole, $owner);
        $this->grantSchema(self::TENANT_SCHEMA, $tenantRole, $owner);

        // RAG embeddings — created in `public` so BOTH runtime roles can use the
        // `vector` type and operators (every role has USAGE on public by default),
        // even though tenant tables live in `tenant` and platform tables in `platform`.
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector SCHEMA public');
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        // Intentionally conservative: drop the schemas (and their tables) but
        // leave the cluster-global roles in place — on Supabase they are
        // managed outside this app and may be shared.
        DB::statement('DROP SCHEMA IF EXISTS '.self::TENANT_SCHEMA.' CASCADE');
        DB::statement('DROP SCHEMA IF EXISTS '.self::PLATFORM_SCHEMA.' CASCADE');
    }

    /**
     * Create the LOGIN role if it does not already exist (no-op on Supabase).
     */
    private function ensureRole(string $role, string $password): void
    {
        $roleLiteral = $this->quoteLiteral($role);
        $passwordLiteral = $this->quoteLiteral($password);

        DB::statement(<<<SQL
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = {$roleLiteral}) THEN
                    EXECUTE format('CREATE ROLE %I LOGIN PASSWORD %L', {$roleLiteral}, {$passwordLiteral});
                END IF;
            END
            \$\$;
        SQL);
    }

    /**
     * Grant a runtime role exclusive access to its schema and arrange for every
     * future owner-created object in that schema to be granted to it as well.
     */
    private function grantSchema(string $schema, string $role, string $owner): void
    {
        $schemaIdent = $this->quoteIdentifier($schema);
        $roleIdent = $this->quoteIdentifier($role);
        $ownerIdent = $this->quoteIdentifier($owner);

        DB::statement("GRANT USAGE ON SCHEMA {$schemaIdent} TO {$roleIdent}");

        // Existing objects (none on first run; safe if re-run after tables exist).
        DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$schemaIdent} TO {$roleIdent}");
        DB::statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {$schemaIdent} TO {$roleIdent}");

        // Future objects created by the owner in this schema.
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerIdent} IN SCHEMA {$schemaIdent} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$roleIdent}");
        DB::statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerIdent} IN SCHEMA {$schemaIdent} GRANT USAGE, SELECT ON SEQUENCES TO {$roleIdent}");
    }

    private function quoteIdentifier(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
};
