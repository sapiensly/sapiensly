<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // unsignedSmallInteger (0-65535) is too narrow for WhatsApp provider
            // error codes like 131056. Widen to a 32-bit integer.
            DB::statement('ALTER TABLE whatsapp_messages ALTER COLUMN error_code TYPE integer');
        } else {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->unsignedInteger('error_code')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE whatsapp_messages ALTER COLUMN error_code TYPE smallint');
        } else {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->unsignedSmallInteger('error_code')->nullable()->change();
            });
        }
    }
};
