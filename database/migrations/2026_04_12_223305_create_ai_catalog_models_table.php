<?php

use App\Services\AiProviderService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_catalog_models', function (Blueprint $table) {
            $table->id();
            $table->string('driver');
            $table->string('model_id');
            $table->string('label');
            $table->string('capability'); // "chat" | "embeddings"
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['driver', 'model_id', 'capability']);
            $table->index(['capability', 'is_enabled']);
        });

        // Seed from the service-level constant so existing installations
        // inherit the catalog that used to be hardcoded.
        $now = now();
        $rows = [];

        foreach (AiProviderService::MODEL_CATALOGS as $driver => $models) {
            foreach ($models as $index => $model) {
                foreach ($model['capabilities'] ?? [] as $capability) {
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

        if (! empty($rows)) {
            DB::table('ai_catalog_models')->insert($rows);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_catalog_models');
    }
};
