<?php

namespace App\Models;

use App\Models\Concerns\UsesPlatformConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiCatalogModel extends Model
{
    use UsesPlatformConnection;

    protected $table = 'ai_catalog_models';

    protected $fillable = [
        'driver',
        'model_id',
        'label',
        'capability',
        'context_window',
        'input_price_per_mtok',
        'output_price_per_mtok',
        'price_per_page',
        'price_per_request',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
            'context_window' => 'integer',
            'input_price_per_mtok' => 'float',
            'output_price_per_mtok' => 'float',
            'price_per_page' => 'float',
            'price_per_request' => 'float',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeChat(Builder $query): Builder
    {
        return $query->where('capability', 'chat');
    }

    public function scopeEmbeddings(Builder $query): Builder
    {
        return $query->where('capability', 'embeddings');
    }
}
