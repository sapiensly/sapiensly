<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_files', function (Blueprint $table) {
            $table->string('id', 36)->primary(); // fil_01j...
            $table->string('organization_id', 36)->nullable();
            $table->string('app_id', 36);
            // Disk + storage_path identify where the bytes live. Disk is held
            // as a column (not hardcoded) so a future migration to S3 doesn't
            // strand older records on the local disk.
            $table->string('disk', 30)->default('local');
            $table->string('storage_path', 500);
            $table->string('original_name', 500);
            $table->string('mime', 200);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('app_id')
                ->references('id')
                ->on('apps')
                ->cascadeOnDelete();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['app_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_files');
    }
};
