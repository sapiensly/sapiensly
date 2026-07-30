<?php

namespace App\Models;

use App\Models\Concerns\HasPrefixedUlid;
use App\Models\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-app notification raised by a workflow's `notify.send` step.
 *
 * Tenant data (it names a person and quotes their records), so it lives on the
 * tenant connection under RLS. Addressed to a USER where one is known; a
 * notification aimed at an address with no account is still recorded, so the
 * author can see that the step fired and where it went.
 */
class AppNotification extends Model
{
    use HasPrefixedUlid;
    use UsesTenantConnection;

    protected $fillable = [
        'organization_id',
        'app_id',
        'recipient_user_id',
        'recipient_email',
        'title',
        'body',
        'link',
        'workflow_id',
        'workflow_run_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public static function getIdPrefix(): string
    {
        return 'ntf';
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /**
     * One person's inbox for one app, newest first. RLS already confines this to
     * the tenant; the recipient filter is what makes it THEIRS.
     */
    public function scopeInbox(Builder $query, string $appId, int $userId): Builder
    {
        return $query
            ->where('app_id', $appId)
            ->where('recipient_user_id', $userId)
            ->latest('created_at');
    }
}
