<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // wrun_01j...
            $table->string('organization_id', 36)->nullable();
            $table->string('app_id', 36);
            // workflow_id is the manifest-level id (wkf_*). Not an FK because
            // the source of truth lives in app_versions.manifest.
            $table->string('workflow_id', 36);
            $table->string('trigger_type'); // manual | record.created | record.updated | record.deleted
            $table->jsonb('trigger_payload')->nullable();
            $table->string('status')->default('pending'); // pending | running | completed | failed
            $table->jsonb('variables')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')
                ->on('apps')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['app_id', 'workflow_id', 'status']);
            $table->index(['app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_runs');
    }
};
