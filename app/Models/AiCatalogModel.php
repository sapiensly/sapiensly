<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AiCatalogModel extends Model
{
    protected $table = 'ai_catalog_models';

    protected $fillable = [
        'driver',
        'model_id',
        'label',
        'capability',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
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
