<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Trait for models that use prefixed ULIDs as primary keys.
 *
 * Example IDs:
 * - agent_01JFXYZ...
 * - team_01JFABC...
 * - tool_01JFDEF...
 * - kb_01JFGHI...
 */
trait HasPrefixedUlid
{
    public static function bootHasPrefixedUlid(): void
    {
        static::creating(function (Model $model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = static::generatePrefixedUlid();
            }
        });
    }

    public function initializeHasPrefixedUlid(): void
    {
        $this->usesUniqueIds = true;
    }

    public static function generatePrefixedUlid(): string
    {
        return static::getIdPrefix().'_'.strtolower((string) Str::ulid());
    }

    /**
     * Get the prefix for this model's IDs.
     * Override this method in your model to customize the prefix.
     */
    abstract public static function getIdPrefix(): string;

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
