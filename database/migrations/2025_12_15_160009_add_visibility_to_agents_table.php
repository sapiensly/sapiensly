<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->string('organization_id', 36)->nullable()->after('user_id');
            $table->string('visibility')->default('private')->after('status');

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();

            $table->index(['organization_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['agents_organization_id_visibility_index']);
            $table->dropColumn(['organization_id', 'visibility']);
        });
    }
};
