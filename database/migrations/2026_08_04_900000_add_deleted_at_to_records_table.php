<?php

use App\Support\Tenancy\Schemas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The way back from a delete.
 *
 * Apps have had a version history and a rollback since the builder shipped;
 * records had neither. A row deleted by hand — or, since bulk actions, two
 * hundred rows deleted by one click — was gone, and the only bound on the
 * damage was a cap on how many somebody could destroy at a time. That is not a
 * safety property, it is a smaller hole.
 *
 * A column, not a second table. A trashed row keeps its id, so the relations
 * pointing at it still resolve when it comes back — copying it to an archive
 * and copying it back is where restores lose their children.
 *
 * The index is PARTIAL: it covers the trash, which is a handful of rows against
 * a table that is almost entirely live. A full index here would be paid for on
 * every insert to serve a question nobody asks most days.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('records', 'deleted_at')) {
            Schema::connection($this->connection)->table('records', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (DB::connection($this->connection)->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS records_trash_idx ON '.Schemas::qualify('records').
            ' (app_id, object_definition_id, environment, deleted_at) WHERE deleted_at IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection($this->connection)->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.Schemas::TENANT.'.records_trash_idx');
        }

        if (Schema::connection($this->connection)->hasColumn('records', 'deleted_at')) {
            Schema::connection($this->connection)->table('records', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
