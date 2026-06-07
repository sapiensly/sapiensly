<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Refresh the curated Voyage AI catalog. Voyage exposes no `/models` listing
 * endpoint, so its catalog is hand-maintained: the original seed only carried
 * the now-deprecated voyage-3 / voyage-3-lite. This brings the existing catalog
 * up to the current lineup without a migrate:fresh.
 *
 * Idempotent: upsert refreshes labels/order while preserving each row's admin
 * enable toggle, and the deprecated rows are dropped if still present. On a
 * fresh install the create-table seed already inserts the current lineup, so
 * the upsert is a no-op and the delete matches nothing.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $current = [
        'voyage-4-large' => 'Voyage 4 Large',
        'voyage-4' => 'Voyage 4',
        'voyage-4-lite' => 'Voyage 4 Lite',
        'voyage-4-nano' => 'Voyage 4 Nano',
        'voyage-code-3' => 'Voyage Code 3',
        'voyage-finance-2' => 'Voyage Finance 2',
        'voyage-law-2' => 'Voyage Law 2',
    ];

    /**
     * @var array<int, string>
     */
    private array $deprecated = ['voyage-3', 'voyage-3-lite'];

    public function up(): void
    {
        $now = now();
        $rows = [];
        $index = 0;

        foreach ($this->current as $modelId => $label) {
            $rows[] = [
                'driver' => 'voyageai',
                'model_id' => $modelId,
                'capability' => 'embeddings',
                'label' => $label,
                'is_enabled' => true,
                'sort_order' => $index++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('ai_catalog_models')->upsert(
            $rows,
            ['driver', 'model_id', 'capability'],
            ['label', 'sort_order', 'updated_at'],
        );

        DB::table('ai_catalog_models')
            ->where('driver', 'voyageai')
            ->where('capability', 'embeddings')
            ->whereIn('model_id', $this->deprecated)
            ->delete();
    }

    public function down(): void
    {
        DB::table('ai_catalog_models')
            ->where('driver', 'voyageai')
            ->where('capability', 'embeddings')
            ->whereIn('model_id', array_keys($this->current))
            ->delete();

        $now = now();
        DB::table('ai_catalog_models')->insertOrIgnore([
            ['driver' => 'voyageai', 'model_id' => 'voyage-3', 'capability' => 'embeddings', 'label' => 'Voyage 3', 'is_enabled' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['driver' => 'voyageai', 'model_id' => 'voyage-3-lite', 'capability' => 'embeddings', 'label' => 'Voyage 3 Lite', 'is_enabled' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
};
