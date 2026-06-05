<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file uploaded against an App. We keep the bytes on the configured disk
 * (default: local private) and only expose them through the runtime
 * `/r/{app_slug}/files/{file_id}` endpoint that checks tenant access — this
 * keeps uploads behind the same gate as the rest of the app's data.
 */
class AppFile extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'disk',
        'storage_path',
        'original_name',
        'mime',
        'size_bytes',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'fil';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
