<?php

use App\Services\AiProviderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the specialized-capability catalog rows (vision, image, transcription,
 * speech, rerank) added to {@see AiProviderService::MODEL_CATALOGS}. On a fresh
 * install the create-table seed already inserts them; this brings existing
 * installations up to the current catalog without a migrate:fresh.
 *
 * Idempotent: upsert refreshes label/sort_order while PRESERVING each row's
 * admin enable toggle (is_enabled is set only on insert, never on update).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $capabilities = ['vision', 'image', 'transcription', 'speech', 'rerank'];

    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (AiProviderService::MODEL_CATALOGS as $driver => $models) {
            foreach ($models as $index => $model) {
                foreach ($model['capabilities'] ?? [] as $capability) {
                    if (! in_array($capability, $this->capabilities, true)) {
                        continue;
                    }

                    $rows[] = [
                        'driver' => $driver,
                        'model_id' => $model['id'],
                        'label' => $model['label'],
                        'capability' => $capability,
                        'is_enabled' => true,
                        'sort_order' => $index,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        if ($rows !== []) {
            DB::table('ai_catalog_models')->upsert(
                $rows,
                ['driver', 'model_id', 'capability'],
                ['label', 'sort_order', 'updated_at'],
            );
        }
    }

    public function down(): void
    {
        DB::table('ai_catalog_models')
            ->whereIn('capability', $this->capabilities)
            ->delete();
    }
};
