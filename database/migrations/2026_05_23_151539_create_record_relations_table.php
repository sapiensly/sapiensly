<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_relations', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // rel_01j...
            $table->string('organization_id', 36)->nullable();
            $table->string('app_id', 36);
            // relation_field_id is the manifest-level id of the relation field
            // (e.g. fld_01j...). Not an FK — the field definition lives in
            // app_versions.manifest, not a relational table.
            $table->string('relation_field_id', 36);
            $table->string('from_record_id', 36);
            $table->string('to_record_id', 36);
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')
                ->on('apps')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->foreign('from_record_id')
                ->references('id')
                ->on('records')
                ->cascadeOnDelete();

            $table->foreign('to_record_id')
                ->references('id')
                ->on('records')
                ->cascadeOnDelete();

            $table->unique(['relation_field_id', 'from_record_id', 'to_record_id'], 'record_relations_unique');
            $table->index(['from_record_id', 'relation_field_id']);
            $table->index(['to_record_id', 'relation_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_relations');
    }
};
