<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organization_memberships')
            ->where('role', 'admin')
            ->update(['role' => 'owner']);
    }

    public function down(): void
    {
        DB::table('organization_memberships')
            ->where('role', 'owner')
            ->update(['role' => 'admin']);
    }
};
