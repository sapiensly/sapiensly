<?php

use App\Services\AiProviderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove Azure OpenAI from the model catalog for good — we will never use it,
 * and it was actively harmful: Azure and OpenAI shared the same `model_id`
 * (gpt-4o-mini), and provider resolution picked the alphabetically-first driver
 * (azure, which has no configured provider), so a setting pointed at the OpenAI
 * model silently routed to a dead Azure endpoint and every call fell back.
 *
 * Azure is also dropped from {@see AiProviderService::MODEL_CATALOGS}
 * so a fresh install never seeds it again. This migration cleans existing DBs:
 * it repoints any AI-default setting that referenced an Azure catalog row to the
 * equivalent Anthropic vision model (which runs on the env key), then deletes
 * the Azure rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        $azureIds = DB::table('ai_catalog_models')
            ->where('driver', 'azure')
            ->pluck('id')
            ->map(fn ($id): string => (string) $id);

        if ($azureIds->isEmpty()) {
            return;
        }

        // A safe, always-present non-Azure vision model to inherit any orphaned
        // setting (image_vision pointed at Azure gpt-4o).
        $fallbackId = DB::table('ai_catalog_models')
            ->where('driver', 'anthropic')
            ->where('model_id', 'claude-haiku-4-5-20251001')
            ->where('capability', 'vision')
            ->value('id');

        if ($fallbackId !== null) {
            DB::table('app_settings')
                ->where('key', 'like', 'admin_v2.ai.%')
                ->whereIn('value', $azureIds->all())
                ->update(['value' => (string) $fallbackId]);
        }

        DB::table('ai_catalog_models')->where('driver', 'azure')->delete();
    }

    public function down(): void
    {
        // Azure's removal is intentional and permanent — no rollback.
    }
};
