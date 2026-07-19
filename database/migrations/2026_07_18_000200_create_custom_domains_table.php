<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom domains for published landings (Cloudflare for SaaS): a tenant points
 * their own hostname (landing.acme.com) at our edge and the landing serves at
 * its root. Control-plane config — platform schema (the owner connection's
 * search_path puts new tables there), structural isolation like `apps`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_domains', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('organization_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('app_id')->index();
            /** The customer's hostname, globally unique — the routing key. */
            $table->string('hostname', 253)->unique();
            /** pending → active; failed kept for observability. */
            $table->string('status', 20)->default('pending');
            /** Cloudflare custom-hostname id when the SaaS API is configured. */
            $table->string('cf_hostname_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->foreign('app_id')->references('id')->on('apps')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_domains');
    }
};
