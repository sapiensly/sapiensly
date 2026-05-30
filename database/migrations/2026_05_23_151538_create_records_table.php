<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // rec_01j...
            $table->string('organization_id', 36)->nullable();
            $table->string('app_id', 36);
            // object_definition_id is the manifest-level id (e.g. obj_01j...).
            // It is NOT an FK because the source of truth is the JSON manifest
            // — objects live in app_versions.manifest, not a relational table.
            $table->string('object_definition_id', 36);
            $table->jsonb('data');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')
                ->on('apps')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['organization_id', 'app_id', 'object_definition_id', 'created_at'], 'records_scope_idx');
            $table->index('object_definition_id');
        });

        // GIN index on jsonb data for fast key/value filtering. jsonb_path_ops
        // is faster and smaller than the default jsonb_ops but only supports
        // the @> containment operator — fine for our equality filters; range
        // queries fall back to expression indexes on demand.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX records_data_gin_idx ON records USING gin (data jsonb_path_ops)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS records_data_gin_idx');
        }

        Schema::dropIfExists('records');
    }
};
