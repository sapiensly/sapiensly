<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot in a presentation's history ("Living Decks").
 * `manifest` carries the live data bindings resolved and baked (openable
 * without the sources); `source_manifest` keeps the authored bindings for
 * restore; `data_digest` fingerprints the live data for change detection.
 */
class DeckVersion extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'user_id',
        'document_id',
        'version_number',
        'cause',
        'manifest',
        'source_manifest',
        'data_digest',
        'change_summary',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'manifest' => 'array',
            'source_manifest' => 'array',
            'data_digest' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'dkv';
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
