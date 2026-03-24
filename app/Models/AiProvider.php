<?php

namespace App\Models;

use App\Enums\Visibility;
use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\HasVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProvider extends Model
{
    use HasFactory, HasPrefixedUlid, HasVisibility;

    protected $fillable = [
        'user_id',
        'organization_id',
        'visibility',
        'name',
        'driver',
        'display_name',
        'credentials',
        'models',
        'is_default',
        'is_default_embeddings',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'models' => 'array',
            'visibility' => Visibility::class,
            'is_default' => 'boolean',
            'is_default_embeddings' => 'boolean',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'aiprov';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the enabled chat models from this provider.
     */
    public function getChatModels(): array
    {
        return collect($this->models ?? [])
            ->filter(fn (array $model) => in_array('chat', $model['capabilities'] ?? []))
            ->values()
            ->all();
    }

    /**
     * Get the enabled embedding models from this provider.
     */
    public function getEmbeddingModels(): array
    {
        return collect($this->models ?? [])
            ->filter(fn (array $model) => in_array('embeddings', $model['capabilities'] ?? []))
            ->values()
            ->all();
    }

    /**
     * Check if this provider has a given model.
     */
    public function hasModel(string $modelId): bool
    {
        return collect($this->models ?? [])
            ->contains(fn (array $model) => $model['id'] === $modelId);
    }
}
