<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix permission tables to allow null organization_id for global roles (e.g. SysAdmin).
 *
 * PostgreSQL does not allow NULL in primary key columns, so we replace the
 * composite primary key with a unique index that supports nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // model_has_roles: drop PK, make nullable, add unique index
        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->dropPrimary('model_has_roles_role_model_type_primary');
        });

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->string('organization_id', 36)->nullable()->change();
        });

        DB::statement("
            CREATE UNIQUE INDEX model_has_roles_unique
            ON model_has_roles (COALESCE(organization_id, '__null__'), role_id, model_id, model_type)
        ");

        // model_has_permissions: drop PK, make nullable, add unique index
        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->dropPrimary('model_has_permissions_permission_model_type_primary');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->string('organization_id', 36)->nullable()->change();
        });

        DB::statement("
            CREATE UNIQUE INDEX model_has_permissions_unique
            ON model_has_permissions (COALESCE(organization_id, '__null__'), permission_id, model_id, model_type)
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS model_has_roles_unique');
        DB::statement('DROP INDEX IF EXISTS model_has_permissions_unique');

        DB::statement("UPDATE model_has_roles SET organization_id = '' WHERE organization_id IS NULL");
        DB::statement("UPDATE model_has_permissions SET organization_id = '' WHERE organization_id IS NULL");

        Schema::table('model_has_roles', function (Blueprint $table) {
            $table->string('organization_id', 36)->default('')->nullable(false)->change();
            $table->primary(['organization_id', 'role_id', 'model_id', 'model_type'], 'model_has_roles_role_model_type_primary');
        });

        Schema::table('model_has_permissions', function (Blueprint $table) {
            $table->string('organization_id', 36)->default('')->nullable(false)->change();
            $table->primary(['organization_id', 'permission_id', 'model_id', 'model_type'], 'model_has_permissions_permission_model_type_primary');
        });
    }
};
