<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuilderMessage extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'proposed_patch',
        'change_summary',
        'plan',
        'integration_proposal',
        'status',
        'applied_version_id',
        'attachment_path',
        'attachment_mime',
        'attachment_disk',
    ];

    protected function casts(): array
    {
        return [
            'proposed_patch' => 'array',
            'plan' => 'array',
            'integration_proposal' => 'array',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'bmsg';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(BuilderConversation::class, 'conversation_id');
    }

    public function appliedVersion(): BelongsTo
    {
        return $this->belongsTo(AppVersion::class, 'applied_version_id');
    }
}
