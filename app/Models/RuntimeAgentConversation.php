<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversation an end-user of a built app has with its embedded runtime agent
 * (builder power #3). Tenant data — RLS-scoped to the owning tenant.
 */
class RuntimeAgentConversation extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'user_id',
        'status',
    ];

    public static function getIdPrefix(): string
    {
        return 'rconv';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'app_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RuntimeAgentMessage::class, 'conversation_id')->orderBy('created_at')->orderBy('id');
    }
}
