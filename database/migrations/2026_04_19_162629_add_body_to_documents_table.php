<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores inline-authored document content (Text, Markdown, HTML artifact)
 * directly on the documents table. File-uploaded documents keep `body` null
 * and rely on `file_path` as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('body')->nullable()->after('file_size');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};
